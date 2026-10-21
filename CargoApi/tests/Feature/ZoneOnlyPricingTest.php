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
use App\Domain\Pricing\Models\TruckSize;
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
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        $this->unpriced->forceFill(['trucker_id' => $trucker->id])->save();

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$this->unpriced->id}/accept")
            ->assertStatus(422);

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

describe('a trip on a sized truck', function (): void {
    beforeEach(function (): void {
        $this->tenSize = TruckSize::query()->where('key', '10-wheeler')->firstOrFail();
        ($this->card)();
    });

    it('takes the size from the vehicle and prices it ÷ 28 × 54', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", ['truck_size_id' => $this->tenSize->id])
            ->assertOk()
            ->assertJsonPath('data.truck_size_id', $this->tenSize->id);

        $trip = ($this->book)(120, ['vehicle_id' => $this->vehicle->id])->assertCreated()->json('data');

        expect($trip['truck_size_id'])->toBe($this->tenSize->id)
            ->and($trip['price_cents'])->toBe(1_928_571);
    });

    it('keeps the type and the size of a vehicle apart', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", [
                'truck_category_id' => $this->tenWheeler->id,
                'truck_size_id' => $this->tenSize->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.truck_category_id', $this->tenWheeler->id)
            ->assertJsonPath('data.truck_size_id', $this->tenSize->id);

        $trip = ($this->book)(120, ['vehicle_id' => $this->vehicle->id])->assertCreated()->json('data');

        expect($trip['truck_category_id'])->toBe($this->tenWheeler->id)
            ->and($trip['truck_size_id'])->toBe($this->tenSize->id);
    });

    it('prices a 28 cbm vehicle off the card as it stands', function (): void {
        $trip = ($this->book)(120, ['vehicle_id' => $this->vehicle->id])->assertCreated()->json('data');

        expect($trip['truck_size_id'])->toBeNull()
            ->and($trip['price_cents'])->toBe(1_000_000);
    });

    it('reprices when the trip moves to a 10 wheeler and back', function (): void {
        $plain = Vehicle::query()->whereKeyNot($this->vehicle->id)->firstOrFail();
        $this->vehicle->update(['truck_size_id' => $this->tenSize->id]);

        $trip = ($this->book)(120, ['vehicle_id' => $plain->id])->assertCreated()->json('data');

        $moved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip['id']}", ['vehicle_id' => $this->vehicle->id])
            ->assertOk()->json('data');

        expect($moved['price_cents'])->toBe(1_928_571);

        $back = $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip['id']}", ['vehicle_id' => $plain->id])
            ->assertOk()->json('data');

        expect($back['truck_size_id'])->toBeNull()
            ->and($back['price_cents'])->toBe(1_000_000);
    });

    it('keeps a size the desk picked by hand', function (): void {
        $this->vehicle->update(['truck_size_id' => null]);

        $trip = ($this->book)(120, [
            'vehicle_id' => $this->vehicle->id,
            'truck_size_id' => $this->tenSize->id,
        ])->assertCreated()->json('data');

        expect($trip['price_cents'])->toBe(1_928_571);
    });
});

describe('a truck sized by its wheels', function (): void {
    beforeEach(function (): void {
        $this->tenSize = TruckSize::query()->where('key', '10-wheeler')->firstOrFail();
        ($this->card)();
    });

    it('makes a ten-wheel truck a 10 wheeler without being asked, and prices its trips ÷ 28 × 54', function (): void {
        $made = $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
            'plate' => 'TEN 0010',
            'model' => 'Fuso Fighter',
            'registration_no' => 'LTO-2026-01010',
            'capacity_kg' => 18_000,
            'arrangement' => 'rented',
            'rent_cents' => 5_000_000,
            'wheels' => 10,
        ])->assertCreated()->json('data');

        expect($made['truck_size_id'])->toBe($this->tenSize->id);

        $trip = ($this->book)(120, ['vehicle_id' => $made['id']])->assertCreated()->json('data');

        expect($trip['price_cents'])->toBe(1_928_571);
    });

    it('sizes a truck when its wheels are corrected to ten', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", ['wheels' => 10])
            ->assertOk()
            ->assertJsonPath('data.truck_size_id', $this->tenSize->id);
    });

    it('leaves a six-wheel truck at the card size', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", ['wheels' => 6])
            ->assertOk()
            ->assertJsonPath('data.truck_size_id', null);
    });

    it('sizes the ten-wheel trucks already on the fleet when the size is added', function (): void {
        $this->tenSize->forceDelete();
        $this->vehicle->update(['wheels' => 10, 'truck_size_id' => null]);

        $made = $this->actingAs($this->admin)
            ->postJson('/api/v1/pricing/truck-sizes', ['name' => '10 Wheeler', 'cbm' => 54])
            ->assertCreated()->json('data');

        expect($this->vehicle->refresh()->truck_size_id)->toBe($made['id']);
    });

    it('keeps a size the office picked over the wheel count', function (): void {
        $big = TruckSize::create(['key' => 'big', 'name' => 'Big box', 'cbm' => 70]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}", ['wheels' => 10, 'truck_size_id' => $big->id])
            ->assertOk()
            ->assertJsonPath('data.truck_size_id', $big->id);
    });
});

describe('a partner truck of the wrong size', function (): void {
    it('is not offered a run that needs a 10 wheeler', function (): void {
        $ten = TruckSize::query()->where('key', '10-wheeler')->firstOrFail();
        $small = TruckSize::query()->where('key', 'small-truck')->firstOrFail();

        $unit = TruckerVehicle::factory()->make(['truck_size_id' => $small->id]);

        expect($unit->canCarry(1_000, null, $ten->id))->toBeFalse()
            ->and($unit->canCarry(1_000, null, $small->id))->toBeTrue()
            ->and($unit->canCarry(1_000, null, null))->toBeTrue();
    });
});

describe('moving sizes off the truck categories', function (): void {
    it('turns a sized category into a size and carries its trucks and trips across', function (): void {
        ($this->card)();
        $trip = ($this->book)(120, ['vehicle_id' => $this->vehicle->id])->assertCreated()->json('data');
        $other = Vehicle::query()->whereKeyNot($this->vehicle->id)->firstOrFail();

        $migration = require database_path('migrations/2026_10_21_000100_separate_truck_sizes_from_categories.php');
        $migration->down();

        $ten = TruckCategory::create(['key' => 'ten-wheeler-box', 'name' => 'Ten Wheeler Box']);
        $reefer = TruckCategory::create(['key' => 'big-reefer', 'name' => 'Big Reefer']);
        DB::table('truck_categories')->where('id', $ten->id)->update(['cbm' => 54]);
        DB::table('truck_categories')->where('id', $reefer->id)->update(['cbm' => 40]);
        PricingBracket::create([
            'zone_id' => PricingZone::query()->value('id'), 'truck_category_id' => $reefer->id,
            'label' => 'Reefer', 'base_cents' => 1_200_000, 'position' => 1,
        ]);

        DB::table('vehicles')->where('id', $this->vehicle->id)->update(['truck_category_id' => $ten->id]);
        DB::table('vehicles')->where('id', $other->id)->update(['truck_category_id' => $reefer->id]);
        DB::table('trips')->where('id', $trip['id'])->update(['truck_category_id' => $ten->id]);

        $migration->up();

        $sizes = TruckSize::query()->pluck('cbm', 'key')->all();

        expect($sizes)->toEqual([
            'big-reefer' => 40,
            'small-truck' => 17,
            'standard-truck' => 28,
            'ten-wheeler-box' => 54,
        ]);

        $tenSize = TruckSize::query()->where('key', 'ten-wheeler-box')->value('id');
        $reeferSize = TruckSize::query()->where('key', 'big-reefer')->value('id');

        expect($this->vehicle->refresh()->only(['truck_category_id', 'truck_size_id']))
            ->toBe(['truck_category_id' => null, 'truck_size_id' => $tenSize])
            ->and(Trip::findOrFail($trip['id'])->only(['truck_category_id', 'truck_size_id']))
            ->toBe(['truck_category_id' => null, 'truck_size_id' => $tenSize])
            ->and(TruckCategory::query()->whereKey($ten->id)->exists())->toBeFalse()
            ->and($other->refresh()->only(['truck_category_id', 'truck_size_id']))
            ->toBe(['truck_category_id' => $reefer->id, 'truck_size_id' => $reeferSize])
            ->and(TruckCategory::query()->whereKey($reefer->id)->exists())->toBeTrue();
    });
});
