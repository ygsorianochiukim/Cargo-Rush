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

/**
 * Trip Monitoring is one row per truck per day (DESIGN.md section 5.1), and it
 * used to hear about a day only if somebody recorded one — so a trip could be
 * delivered and the sheet stay empty. Delivering now opens the row.
 *
 * It also **fills in the income**, which it did not before. The rule that
 * income is entered rather than derived came from the transcribed workbook,
 * where a row is a record of a day already priced and already agreed. It could
 * not survive a customer booking their own delivery: there is nobody to type a
 * figure at that point, and a haul whose price nobody recorded earned the
 * business nothing on its own books. So the trip is quoted from the tariff when
 * it is booked, and the delivery credits that figure here.
 *
 * The expenses stay entered. A trip knows what it was charged; it has no idea
 * what the fuel cost.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    /** Book a run, put it on the road, and hand it over. */
    $this->deliver = function (array $overrides = [], string $receiver = 'L. Tan'): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Manila',
            'destination' => 'Batangas',
            'cargo' => 'Dry goods, 12 pallets',
            'weight_kg' => 3200,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            // Confirmed work with a crew and a unit, which is the only state a
            // driver can leave on. `pending` now means a request nobody has
            // looked at yet.
            'status' => StatusValue::Assigned->value,
            ...$overrides,
        ])->json('data.id');

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

    // Read off the trip rather than recomputed here: the tariff is
    // configuration and this test is about the money reaching the sheet, not
    // about what the rates happen to be. Asserting it is above zero is what
    // makes the read meaningful — a quote of nothing would pass a comparison
    // against itself.
    expect($trip->price_cents)->toBeGreaterThan(0)
        ->and($row->trip_income_cents)->toBe($trip->price_cents);
});

it('leaves the expenses at zero for somebody to enter', function (): void {
    ($this->deliver)();

    $row = LedgerEntry::firstOrFail();

    // A trip carries a price. It has no idea what the fuel, the salaries or
    // the maintenance came to, and inventing them would put figures the
    // business never agreed to into Profitability and the Quarterly Summary.
    expect($row->fuel_cents)->toBe(0)
        ->and($row->driver_salary_cents)->toBe(0)
        ->and($row->helper_salary_cents)->toBe(0)
        ->and($row->maintenance_cents)->toBe(0)
        ->and($row->allowance_cents)->toBe(0)
        // Derived from the five above, so it follows from the zeros.
        ->and($row->totalExpensesCents())->toBe(0)
        // And net income is then the whole of the trip income.
        ->and($row->netIncomeCents())->toBe($row->trip_income_cents);
});

it('marks the trip as billed so the money cannot move twice', function (): void {
    $id = ($this->deliver)();

    expect(Trip::findOrFail($id)->billed_at)->not->toBeNull();
});

it('refuses to close a run that has already been delivered', function (): void {
    // The office pressing Complete on a run the driver already closed. Both
    // credit income and raise an invoice, so the second attempt has to be
    // refused rather than quietly repeated.
    $id = ($this->deliver)();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/trips/{$id}/complete", ['receiver_name' => 'L. Tan'])
        ->assertStatus(422);

    expect(LedgerEntry::firstOrFail()->trip_income_cents)
        ->toBe(Trip::findOrFail($id)->price_cents);
});

it('creates the ledger sheet for a unit that has none yet', function (): void {
    // This is the state that made Monitoring look broken: a fleet with no
    // sheets shows nothing, whatever happens on the road.
    expect(Truck::count())->toBe(0);

    ($this->deliver)();

    $truck = Truck::firstOrFail();

    expect($truck->vehicle_id)->toBe($this->vehicle->id)
        ->and($truck->plate)->toBe('NCR 4412')
        ->and($truck->label)->toBe('Truck 1')
        ->and(LedgerEntry::firstOrFail()->truck_id)->toBe($truck->id);
});

it('files against the sheet a unit already has', function (): void {
    // Matched on the vehicle, not the plate — so a sheet whose plate was typed
    // differently is still the same unit's sheet.
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
    // The workbook keeps one line per truck per day, however many runs went
    // into it. The first delivery opens the row; the second finds it — and the
    // day is worth both hauls, which is why the credit is an increment and not
    // a write.
    $first = ($this->deliver)();
    $second = ($this->deliver)();

    $expected = Trip::findOrFail($first)->price_cents + Trip::findOrFail($second)->price_cents;

    expect($first)->not->toBe($second)
        ->and(Trip::where('status', StatusValue::Delivered->value)->count())->toBe(2)
        ->and(LedgerEntry::count())->toBe(1)
        ->and(LedgerEntry::firstOrFail()->trip_income_cents)->toBe($expected)
        // It still names the run that opened it, not the last one in.
        ->and(LedgerEntry::firstOrFail()->trip_id)->toBe($first);
});

it('adds to figures already recorded for the day rather than replacing them', function (): void {
    // The driver records the day from the cab, then delivers a second run. The
    // second delivery adds its fare to what they entered and leaves the
    // expenses alone — it must not reset either.
    $first = ($this->deliver)();

    $recorded = LedgerEntry::firstOrFail();
    $afterFirst = $recorded->trip_income_cents;

    $recorded->update(['trip_income_cents' => 750_00, 'fuel_cents' => 120_00]);

    $second = ($this->deliver)();

    $row = LedgerEntry::firstOrFail();

    expect($afterFirst)->toBeGreaterThan(0)
        ->and(LedgerEntry::count())->toBe(1)
        ->and($row->trip_income_cents)->toBe(750_00 + Trip::findOrFail($second)->price_cents)
        // Nothing about a delivery knows what the fuel cost.
        ->and($row->fuel_cents)->toBe(120_00);
});

it('files nothing for a run with no unit assigned', function (): void {
    // Work booked before a vehicle is picked. There is no sheet to file
    // against, and inventing one would put a truck on the ledger that the
    // business does not run.
    $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Manila',
        'destination' => 'Batangas',
        'cargo' => 'Dry goods',
        'weight_kg' => 1000,
        'driver_id' => $this->driver->id,
        'scheduled_at' => now()->toIso8601String(),
        'status' => StatusValue::Assigned->value,
    ])->json('data.id');

    $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
    $this->actingAs($this->marco)
        ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'L. Tan'])
        ->assertOk();

    expect(LedgerEntry::count())->toBe(0)
        ->and(Truck::count())->toBe(0)
        // The delivery itself still went through — the ledger is a side
        // effect of it, not a precondition for it.
        ->and(Trip::findOrFail($id)->status)->toBe(StatusValue::Delivered)
        // And the proof is still filed, with a reference nobody typed.
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

it('keeps the day when its trip is deleted', function (): void {
    // A day of income and expenses must not disappear because somebody removed
    // the trip it was opened by — Profitability and the Quarterly Summary are
    // built from these rows.
    $id = ($this->deliver)();

    LedgerEntry::firstOrFail()->update(['trip_income_cents' => 900_00]);

    $this->actingAs($this->admin)->deleteJson("/api/v1/trips/{$id}")->assertSuccessful();

    $row = LedgerEntry::firstOrFail();

    // `trip_id` survives because a trip is soft-deleted: the row is still
    // there to be restored, so the link is not dangling and the migration's
    // `nullOnDelete` never fires. What matters is that the money is intact.
    expect($row->trip_income_cents)->toBe(900_00)
        ->and($row->route)->toBe('Manila → Batangas')
        ->and($row->trip_id)->toBe($id);
});
