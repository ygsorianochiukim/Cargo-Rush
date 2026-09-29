<?php

declare(strict_types=1);

use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\PricingService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A trip is priced off the zone card, by hand, or not at all yet.
 *
 * The office's decision: a run is charged what a line of a zone covering it
 * says, for the class of truck it asked for. Nothing else prices a trip — not
 * the old zoneless distance card, not the config tariff. A run the zones miss
 * is saved **unpriced** (a null figure and the reason), may sit as a request,
 * and may not go any further until somebody adds the zone line or — holding
 * `pricing.manage` — types a price.
 *
 * What these pin, in the order the rule was stated:
 *
 *   1. A zone line prices it; a zoneless line never does.
 *   2. No zone line → unpriced, with the reason and `needs_zone`.
 *   3. Unpriced cannot be confirmed, dispatched, handed to a trucker,
 *      delivered or billed, and never reaches a trucker's board.
 *   4. `cargo:trips-quote` prices it once the card covers it.
 *   5. A typed price is `pricing.manage`'s, marked manual, and never re-quoted.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->dispatcher = User::create([
        'name' => 'Desk', 'email' => 'desk@test.test',
        'password' => 'password', 'role' => Role::Dispatcher->value,
    ]);

    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();

    $this->tenWheeler = TruckCategory::create(['key' => 'ten-wheeler', 'name' => '10-wheeler']);

    /** The office booking a run, `$km` long. */
    $this->book = fn (int $km, array $overrides = [], ?User $as = null) => $this->actingAs($as ?? $this->admin)
        ->postJson('/api/v1/trips', [
            'customer_id' => $this->customer->id,
            'origin' => 'Cagayan de Oro',
            'destination' => 'Somewhere',
            'cargo' => 'Dry goods',
            'weight_kg' => 1_000,
            'distance_total_m' => $km * 1_000,
            'status' => StatusValue::Pending->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            ...$overrides,
        ]);

    /** A 0–600 km card, one general line at a flat ₱10,000. */
    $this->card = function (?string $truckCategoryId = null): PricingZone {
        $zone = PricingZone::create([
            'name' => 'Up to 600', 'code' => 'Z', 'min_km' => 0, 'max_km' => 601,
            'position' => 0, 'status' => 'active',
        ]);

        PricingBracket::create([
            'zone_id' => $zone->id, 'truck_category_id' => $truckCategoryId,
            'label' => 'Fleet', 'base_cents' => 1_000_000, 'position' => 0,
        ]);

        return $zone;
    };

    $this->quote = fn (array $payload) => $this->actingAs($this->admin)
        ->postJson('/api/v1/pricing/quote', $payload);
});

describe('what prices a run', function (): void {
    it('prices it off the line of the zone that covers it', function (): void {
        ($this->card)();

        $trip = ($this->book)(120)->assertCreated()->json('data');

        expect($trip['price_cents'])->toBe(1_000_000)
            ->and($trip['needs_zone'])->toBeFalse()
            ->and($trip['pricing_source'])->toBe('zone')
            ->and($trip['manually_priced'])->toBeFalse()
            ->and($trip['pricing_note'])->toBeNull();
    });

    it('ignores a zoneless line, even one that covers the run', function (): void {
        // The old plain distance card, as an older install still holds it.
        PricingBracket::create([
            'zone_id' => null, 'label' => 'Anywhere', 'min_km' => 0, 'max_km' => null,
            'base_cents' => 500_000, 'position' => 0,
        ]);

        $trip = ($this->book)(120)->assertCreated()->json('data');

        expect($trip['price_cents'])->toBeNull()
            ->and($trip['needs_zone'])->toBeTrue()
            ->and($trip['pricing_source'])->toBe('unzoned');

        ($this->quote)(['distance_km' => 120])->assertOk()
            ->assertJsonPath('data.cents', null)
            ->assertJsonPath('data.needs_zone', true);
    });
});

describe('a run no zone line covers', function (): void {
    it('is saved unpriced past the end of the table, with the reason', function (): void {
        ($this->card)();

        $trip = ($this->book)(712, ['truck_category_id' => $this->tenWheeler->id])
            ->assertCreated()->json('data');

        expect($trip['price_cents'])->toBeNull()
            ->and($trip['needs_zone'])->toBeTrue()
            ->and($trip['pricing_source'])->toBe('unzoned')
            ->and($trip['pricing_note'])->toBe('No zone covers 712 km for a 10-wheeler.');
    });

    it('is saved unpriced when its zone has no line for its class', function (): void {
        $freezer = TruckCategory::create(['key' => 'freezer-x', 'name' => 'Freezer']);
        ($this->card)($freezer->id);

        $trip = ($this->book)(120, ['truck_category_id' => $this->tenWheeler->id])
            ->assertCreated()->json('data');

        expect($trip['price_cents'])->toBeNull()
            ->and($trip['pricing_note'])->toBe('Zone Z (0 – 600 km) has no line for a 10-wheeler.');

        // And the class the zone does price is priced.
        expect(($this->book)(120, ['truck_category_id' => $freezer->id])->json('data.price_cents'))
            ->toBe(1_000_000);
    });

    it('says so on the quote preview instead of offering a figure', function (): void {
        ($this->card)();

        ($this->quote)(['distance_km' => 712, 'truck_category_id' => $this->tenWheeler->id])->assertOk()
            ->assertJsonPath('data.cents', null)
            ->assertJsonPath('data.card_cents', null)
            ->assertJsonPath('data.needs_zone', true)
            ->assertJsonPath('data.source', 'unzoned')
            ->assertJsonPath('data.reason', 'No zone covers 712 km for a 10-wheeler.');

        ($this->quote)(['distance_km' => 120])->assertOk()
            ->assertJsonPath('data.cents', 1_000_000)
            ->assertJsonPath('data.needs_zone', false);
    });

    it('says the card is empty when there are no zones at all', function (): void {
        expect(($this->book)(50)->assertCreated()->json('data.pricing_note'))
            ->toBe('There are no zones on the Pricing card yet, so nothing prices this run.');
    });

    it('still accepts a customer’s own request, unpriced, for the office', function (): void {
        $buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

        $filed = $this->actingAs($buyer)->postJson('/api/v1/portal/requests', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1_800,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data');

        expect($filed['status'])->toBe(StatusValue::Pending->value)
            ->and($filed['price_cents'])->toBeNull()
            ->and($filed['needs_zone'])->toBeTrue();
    });
});

describe('what an unpriced run may not do', function (): void {
    beforeEach(function (): void {
        $this->unpriced = Trip::findOrFail(($this->book)(712)->assertCreated()->json('data.id'));
    });

    it('cannot be booked straight into the diary', function (): void {
        ($this->book)(712, [
            'status' => StatusValue::Scheduled->value,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
        ])->assertStatus(422)->assertJsonPath('message', PricingService::UNPRICED);

        expect(Trip::query()->count())->toBe(1);
    });

    it('cannot be confirmed', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$this->unpriced->id}/confirm", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('message', PricingService::UNPRICED);

        // Refused inside the transaction, so the request is exactly as it was.
        expect($this->unpriced->refresh()->status)->toBe(StatusValue::Pending)
            ->and($this->unpriced->driver_id)->toBeNull();
    });

    it('cannot be dispatched', function (): void {
        $this->unpriced->forceFill(['status' => StatusValue::Assigned->value])->save();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$this->unpriced->id}/dispatch", ['location' => 'Yard'])
            ->assertStatus(422)->assertJsonPath('message', PricingService::UNPRICED);

        expect($this->unpriced->refresh()->status)->toBe(StatusValue::Assigned);
    });

    it('cannot be delivered', function (): void {
        $this->unpriced->forceFill(['status' => StatusValue::InTransit->value])->save();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$this->unpriced->id}/complete", ['receiver_name' => 'Gate'])
            ->assertStatus(422)->assertJsonPath('message', PricingService::UNPRICED);

        expect($this->unpriced->refresh()->billed_at)->toBeNull();
    });

    it('cannot be billed, even by a caller that skips the delivery', function (): void {
        expect(fn () => app(BillingService::class)->raiseForTrip($this->unpriced))
            ->toThrow(HttpException::class, PricingService::UNPRICED);
    });

    it('cannot be handed to a trucker, and never reaches one’s board', function (): void {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);
        $trucker = Trucker::factory()->approved()->create(['user_id' => $user->getKey(), 'name' => 'Boyet']);
        TruckerVehicle::factory()->carrying(15_000)->create(['trucker_id' => $trucker->getKey()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$this->unpriced->id}/assign-trucker", ['trucker_id' => $trucker->id])
            ->assertStatus(422)->assertJsonPath('message', PricingService::UNPRICED);

        // Held for them by name, as a customer's pick would: still not shown,
        // and not acceptable by a caller who knows the id.
        $this->unpriced->forceFill(['trucker_id' => $trucker->id])->save();

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$this->unpriced->id}/accept")
            ->assertStatus(422);

        // Priced, the same offer is on the board.
        $this->unpriced->forceFill(['price_cents' => 1_000_000])->save();

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('pricing the waiting runs', function (): void {
    it('quotes them once a zone line covers them', function (): void {
        $trip = Trip::findOrFail(($this->book)(120)->assertCreated()->json('data.id'));
        expect($trip->price_cents)->toBeNull();

        ($this->card)();

        $this->artisan('cargo:trips-quote')->assertSuccessful();

        $trip->refresh();

        expect($trip->price_cents)->toBe(1_000_000)
            ->and($trip->pricing_source)->toBe('zone')
            ->and($trip->pricing_note)->toBeNull();
    });

    it('leaves a run the card still misses unpriced, with its reason refreshed', function (): void {
        $trip = Trip::findOrFail(($this->book)(712)->assertCreated()->json('data.id'));

        ($this->card)();

        $this->artisan('cargo:trips-quote')->assertSuccessful();

        expect($trip->refresh()->price_cents)->toBeNull()
            ->and($trip->pricing_note)->toBe('No zone covers 712 km.');
    });
});

describe('a price typed by hand', function (): void {
    it('is accepted from somebody who manages the card, and marked manual', function (): void {
        $trip = ($this->book)(712, [
            'price_cents' => 2_500_000,
            'status' => StatusValue::Scheduled->value,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
        ])->assertCreated()->json('data');

        expect($trip['price_cents'])->toBe(2_500_000)
            ->and($trip['needs_zone'])->toBeFalse()
            ->and($trip['manually_priced'])->toBeTrue()
            ->and($trip['pricing_source'])->toBe('manual');
    });

    it('is never re-derived on a later save, even once the card covers the run', function (): void {
        $id = ($this->book)(120, ['price_cents' => 777_700])->assertCreated()->json('data.id');

        ($this->card)();

        // A dispatcher correcting the cargo, and re-sending the figure the form
        // already held — which is not a pricing decision and is let through.
        $this->actingAs($this->dispatcher)
            ->patchJson("/api/v1/trips/{$id}", ['cargo' => 'Dry goods, 14 pallets', 'price_cents' => 777_700])
            ->assertOk()
            ->assertJsonPath('data.price_cents', 777_700)
            ->assertJsonPath('data.pricing_source', 'manual');

        $this->artisan('cargo:trips-quote')->assertSuccessful();

        expect(Trip::findOrFail($id)->price_cents)->toBe(777_700);
    });

    it('goes back to the card when the price is cleared', function (): void {
        ($this->card)();
        $id = ($this->book)(120, ['price_cents' => 777_700])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$id}", ['price_cents' => null])
            ->assertOk()
            ->assertJsonPath('data.price_cents', 1_000_000)
            ->assertJsonPath('data.pricing_source', 'zone');
    });

    it('is refused from somebody who does not manage the card', function (): void {
        ($this->book)(712, ['price_cents' => 2_500_000], $this->dispatcher)->assertForbidden();

        $id = ($this->book)(712, [], $this->dispatcher)->assertCreated()->json('data.id');

        $this->actingAs($this->dispatcher)
            ->patchJson("/api/v1/trips/{$id}", ['price_cents' => 2_500_000])
            ->assertForbidden();

        $this->actingAs($this->dispatcher)->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'price_cents' => 2_500_000,
        ])->assertForbidden();

        expect(Trip::findOrFail($id)->price_cents)->toBeNull();
    });
});
