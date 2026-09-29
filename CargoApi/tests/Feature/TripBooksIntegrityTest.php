<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
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

/**
 * What a delivered run puts on the books, and that it stays put.
 *
 * Three ways the books drifted from the truth. A trucker's ₱10,000 run with a
 * fleet unit left on it was booked as ₱10,000 of company income beside the
 * ₱1,200 commission, when ₱1,200 is all the company keeps. An all-in price put
 * its VAT inside income. And a billed run could be re-priced, moved to another
 * unit, cancelled or deleted with nothing reversing what delivery wrote.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->user = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
    $this->trucker = Trucker::factory()->approved()->create(['user_id' => $this->user->getKey()]);
    TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->id, 'capacity_kg' => 20_000, 'status' => 'available']);

    $this->travelTo(now()->setDate(2026, 8, 12)->setTime(10, 0));

    /** A ₱10,000 run with a fleet unit pencilled in and nobody driving it. */
    $this->pencilled = fn (array $overrides = []): Trip => Trip::create([
        'customer_id' => $this->customer->getKey(), 'origin' => 'Malanang', 'destination' => 'Mambuaya',
        'cargo' => 'Goods', 'weight_kg' => 1_800, 'status' => StatusValue::Pending->value,
        'scheduled_at' => now()->addHour(), 'price_cents' => 1_000_000, 'currency' => 'PHP',
        'vehicle_id' => $this->vehicle->id,
        ...$overrides,
    ]);

    /** The trucker's side of the run: roll out, hand over. */
    $this->partnerDelivers = function (Trip $trip): void {
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();
    };

    /** A fleet run, entered after the fact as delivered — so it is billed at once. */
    $this->fleetDelivered = fn (array $overrides = []): string => $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', [
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Manila', 'destination' => 'Batangas', 'cargo' => 'Dry goods', 'weight_kg' => 3_200,
            'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->subDay()->toIso8601String(),
            'price_cents' => 1_000_000,
            'status' => StatusValue::Delivered->value,
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->quarter = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->assertOk()
        ->json('data.totals');
});

describe('a trucker run', function (): void {
    it('counts only the ₱1,200 commission on a ₱10,000 run the desk handed to a trucker', function (): void {
        $trip = ($this->pencilled)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $this->trucker->id])
            ->assertOk();

        // The fleet unit came off when the trucker went on.
        expect($trip->refresh()->vehicle_id)->toBeNull();

        ($this->partnerDelivers)($trip);

        expect(LedgerEntry::query()->sum('trip_income_cents'))->toEqual(0)
            ->and(($this->quarter)()['total_income_cents'])->toBe(120_000);
    });

    it('books no fleet income for an older row that carries both a trucker and a unit', function (): void {
        $trip = ($this->pencilled)([
            'trucker_id' => $this->trucker->id,
            'booking_source' => 'cargo_rush',
            'status' => StatusValue::InTransit->value,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        expect(LedgerEntry::query()->sum('trip_income_cents'))->toEqual(0)
            ->and(($this->quarter)()['total_income_cents'])->toBe(120_000);
    });

    it('drops the fleet unit when a trucker accepts a run a customer offered them', function (): void {
        $trip = ($this->pencilled)(['trucker_id' => $this->trucker->id]);

        $this->actingAs($this->user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();

        expect($trip->refresh()->vehicle_id)->toBeNull();
    });

    it('refuses a fleet unit on a run a trucker is hauling', function (): void {
        $trip = ($this->pencilled)(['vehicle_id' => null, 'trucker_id' => $this->trucker->id]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip->id}", ['vehicle_id' => $this->vehicle->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/confirm", [
                'driver_id' => $this->driver->id,
                'vehicle_id' => $this->vehicle->id,
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        expect($trip->refresh()->vehicle_id)->toBeNull();
    });
});

describe('VAT', function (): void {
    it('books the net of VAT as income when prices are quoted all-in', function (): void {
        $this->company->update(['prices_include_vat' => true]);

        $id = ($this->fleetDelivered)();

        $income = (int) LedgerEntry::query()->where('trip_id', $id)->sum('trip_income_cents');
        $invoice = Invoice::query()->where('trip_id', $id)->firstOrFail();

        expect($income)->toBe(892_857)
            ->and($income)->toBe((int) $invoice->net_amount_cents)
            // Income and VAT together are exactly the price quoted.
            ->and($income + (int) $invoice->vat_cents)->toBe(1_000_000);
    });

    it('books the whole price as income when VAT is added on top', function (): void {
        $this->company->update(['prices_include_vat' => false]);

        $id = ($this->fleetDelivered)();

        expect((int) LedgerEntry::query()->where('trip_id', $id)->sum('trip_income_cents'))->toBe(1_000_000);
    });
});

describe('a billed run', function (): void {
    beforeEach(function (): void {
        $this->id = ($this->fleetDelivered)();
        $this->other = Vehicle::query()->whereKeyNot($this->vehicle->id)->firstOrFail();
    });

    it('refuses a change to the money on it', function (string $field): void {
        $change = match ($field) {
            'price' => ['price_cents' => 500_000],
            'unit' => ['vehicle_id' => $this->other->id],
            'customer' => ['customer_id' => null],
            'status' => ['status' => StatusValue::Cancelled->value],
        };

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", $change)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'delivered and billed'));

        $trip = Trip::findOrFail($this->id);

        expect($trip->price_cents)->toBe(1_000_000)
            ->and($trip->vehicle_id)->toBe($this->vehicle->id)
            ->and($trip->status)->toBe(StatusValue::Delivered);
    })->with(['price', 'unit', 'customer', 'status']);

    it('refuses to delete it', function (): void {
        $this->actingAs($this->admin)->deleteJson("/api/v1/trips/{$this->id}")->assertStatus(422);

        expect(Trip::find($this->id))->not->toBeNull();
    });

    it('still takes a corrected note, with the same unit and status sent back', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", [
                'cargo' => 'Dry goods, 14 pallets',
                'vehicle_id' => $this->vehicle->id,
                'status' => StatusValue::Delivered->value,
                'price_cents' => 1_000_000,
            ])
            ->assertOk()
            ->assertJsonPath('data.cargo', 'Dry goods, 14 pallets');
    });
});
