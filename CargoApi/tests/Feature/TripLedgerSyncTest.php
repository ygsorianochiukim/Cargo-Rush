<?php

declare(strict_types=1);

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->deliver = function (array $overrides = [], string $receiver = 'L. Tan'): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Manila',
            'destination' => 'Batangas',
            'cargo' => 'Dry goods, 12 pallets',
            'weight_kg' => 3200,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            'status' => StatusValue::Assigned->value,
            ...$overrides,
        ])->json('data.id');

        $this->passPreTripCheck($id);
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
        $this->actingAs($this->marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => $receiver])
            ->assertOk();

        return $id;
    };
});

it('opens the day row on the monitoring sheet when a run is delivered', function (): void {
    expect(LedgerEntry::count())->toBe(0);

    $id = ($this->deliver)();

    $row = LedgerEntry::firstOrFail();

    expect($row->trip_id)->toBe($id)
        ->and($row->route)->toBe('Manila → Batangas')
        ->and($row->date->toDateString())->toBe(now()->toDateString());
});

it('credits the run income the trip was quoted at', function (): void {
    $id = ($this->deliver)();

    $trip = Trip::findOrFail($id);
    $row = LedgerEntry::firstOrFail();

    expect($trip->price_cents)->toBeGreaterThan(0)
        ->and($row->trip_income_cents)->toBe($trip->price_cents);
});

it('leaves the expenses at zero for somebody to enter', function (): void {
    ($this->deliver)();

    $row = LedgerEntry::firstOrFail();

    expect($row->fuel_cents)->toBe(0)
        ->and($row->driver_salary_cents)->toBe(0)
        ->and($row->helper_salary_cents)->toBe(0)
        ->and($row->maintenance_cents)->toBe(0)
        ->and($row->allowance_cents)->toBe(0)
        ->and($row->totalExpensesCents())->toBe(0)
        ->and($row->netIncomeCents())->toBe($row->trip_income_cents);
});

it('marks the trip as billed so the money cannot move twice', function (): void {
    $id = ($this->deliver)();

    expect(Trip::findOrFail($id)->billed_at)->not->toBeNull();
});

it('refuses to close a run that has already been delivered', function (): void {
    $id = ($this->deliver)();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/trips/{$id}/complete", ['receiver_name' => 'L. Tan'])
        ->assertStatus(422);

    expect(LedgerEntry::firstOrFail()->trip_income_cents)
        ->toBe(Trip::findOrFail($id)->price_cents);
});

it('creates the ledger sheet for a unit that has none yet', function (): void {
    expect(Truck::count())->toBe(0);

    ($this->deliver)();

    $truck = Truck::firstOrFail();

    expect($truck->vehicle_id)->toBe($this->vehicle->id)
        ->and($truck->plate)->toBe('NCR 4412')
        ->and($truck->label)->toBe('Truck 1')
        ->and(LedgerEntry::firstOrFail()->truck_id)->toBe($truck->id);
});

it('files against the sheet a unit already has', function (): void {
    $existing = Truck::create([
        'label' => 'Truck 4',
        'plate' => 'ncr-4412',
        'vehicle_id' => $this->vehicle->id,
        'position' => 4,
    ]);

    ($this->deliver)();

    expect(Truck::count())->toBe(1)
        ->and(LedgerEntry::firstOrFail()->truck_id)->toBe($existing->id);
});

it('keeps one row for a unit that runs twice in a day, and adds both fares', function (): void {
    $first = ($this->deliver)();
    $second = ($this->deliver)();

    $expected = Trip::findOrFail($first)->price_cents + Trip::findOrFail($second)->price_cents;

    expect($first)->not->toBe($second)
        ->and(Trip::where('status', StatusValue::Delivered->value)->count())->toBe(2)
        ->and(LedgerEntry::count())->toBe(1)
        ->and(LedgerEntry::firstOrFail()->trip_income_cents)->toBe($expected)
        ->and(LedgerEntry::firstOrFail()->trip_id)->toBe($first);
});

it('adds to figures already recorded for the day rather than replacing them', function (): void {
    $first = ($this->deliver)();

    $recorded = LedgerEntry::firstOrFail();
    $afterFirst = $recorded->trip_income_cents;

    $recorded->update(['trip_income_cents' => 750_00, 'fuel_cents' => 120_00]);

    $second = ($this->deliver)();

    $row = LedgerEntry::firstOrFail();

    expect($afterFirst)->toBeGreaterThan(0)
        ->and(LedgerEntry::count())->toBe(1)
        ->and($row->trip_income_cents)->toBe(750_00 + Trip::findOrFail($second)->price_cents)
        ->and($row->fuel_cents)->toBe(120_00);
});

it('files nothing for a run with no unit assigned', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Manila',
        'destination' => 'Batangas',
        'cargo' => 'Dry goods',
        'weight_kg' => 1000,
        'driver_id' => $this->driver->id,
        'scheduled_at' => now()->toIso8601String(),
        'status' => StatusValue::Assigned->value,
    ])->json('data.id');

    $this->passPreTripCheck($id);
    $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
    $this->actingAs($this->marco)
        ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'L. Tan'])
        ->assertOk();

    expect(LedgerEntry::count())->toBe(0)
        ->and(Truck::count())->toBe(0)
        ->and(Trip::findOrFail($id)->status)->toBe(StatusValue::Delivered)
        ->and(DeliveryLog::where('trip_id', $id)->firstOrFail()->pod_ref)->toStartWith('POD-');
});

it('shows the trip reference on the row the office reads', function (): void {
    ($this->deliver)();

    $reference = Trip::firstOrFail()->reference;

    $this->actingAs($this->admin)
        ->getJson('/api/v1/ledger')
        ->assertOk()
        ->assertJsonPath('data.0.trip_reference', $reference);
});

it('takes the income off the day but keeps the day when an unpaid delivered trip is deleted', function (): void {
    $id = ($this->deliver)();

    LedgerEntry::firstOrFail()->update(['fuel_cents' => 250_00]);

    $this->actingAs($this->admin)->deleteJson("/api/v1/trips/{$id}")->assertSuccessful();

    $row = LedgerEntry::firstOrFail();

    expect(Trip::find($id))->toBeNull()
        ->and($row->trip_income_cents)->toBe(0)
        ->and($row->fuel_cents)->toBe(250_00);
});
