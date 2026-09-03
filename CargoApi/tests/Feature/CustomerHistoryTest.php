<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * A customer's history is three different questions: what was hauled, what was
 * billed for it, and what the work earned and cost.
 *
 * It answered only the first two, so the money recorded against a unit never
 * reached the customer it was earned from — a customer could have a month of
 * hauling behind them and show nothing but trips.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::firstOrFail();

    /** Book a run for a customer, take it out, and hand it over. */
    $this->haul = function (array $overrides = [], string $receiver = 'R. Uy'): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Cagayan de Oro',
            'destination' => 'Iligan',
            'cargo' => 'Assorted retail',
            'weight_kg' => 2400,
            'customer_id' => $this->customer->id,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            // `assigned` is what a driver can leave on: confirmed work with a
            // crew and a unit. `pending` now means a request nobody has
            // looked at, and `start` refuses one.
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

it('carries the customer from the trip onto the day it opens', function (): void {
    // Nobody types this: the run already knows whose load it was.
    ($this->haul)();

    expect(LedgerEntry::firstOrFail()->customer_id)->toBe($this->customer->id);
});

it('puts the day on that customer history', function (): void {
    ($this->haul)();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/customers/{$this->customer->id}/history")
        ->assertOk()
        ->assertJsonCount(1, 'data.ledger_entries')
        ->assertJsonPath('data.ledger_entries.0.customer_id', $this->customer->id)
        ->assertJsonPath('data.ledger_entries.0.customer', $this->customer->name);
});

it('reports the history of somebody with nothing behind them as empty, not missing', function (): void {
    $other = Customer::create([
        'name' => 'Iligan Feeds',
        'contact' => 'R. Uy',
        'phone' => '09180000000',
        'status' => StatusValue::Active->value,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/customers/{$other->id}/history")
        ->assertOk()
        ->assertJsonCount(0, 'data.trips')
        ->assertJsonCount(0, 'data.invoices')
        ->assertJsonCount(0, 'data.ledger_entries');
});

it('keeps a day off the history of a customer it was not earned from', function (): void {
    $other = Customer::create([
        'name' => 'Iligan Feeds',
        'contact' => 'R. Uy',
        'phone' => '09180000000',
        'status' => StatusValue::Active->value,
    ]);

    ($this->haul)();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/customers/{$other->id}/history")
        ->assertOk()
        ->assertJsonCount(0, 'data.ledger_entries');
});

it('files a day with no customer against nobody', function (): void {
    // A run booked without a customer — the company's own freight. The day is
    // still recorded; it just belongs to no history.
    ($this->haul)(['customer_id' => null]);

    $row = LedgerEntry::firstOrFail();

    expect($row->customer_id)->toBeNull()
        ->and($row->trip_id)->not->toBeNull();
});

it('lets the office name the customer on a day it keys in by hand', function (): void {
    // The transcribed workbook has rows with no trip behind them, and the
    // office still knows whose work the day was.
    $truck = $this->actingAs($this->admin)->postJson('/api/v1/finance/trucks', [
        'label' => 'Truck 1',
        'plate' => 'NCR 4412',
    ])->json('data.id');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/ledger', [
            'truck_id' => $truck,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'trip_income_cents' => 45_000_00,
            'fuel_cents' => 6_000_00,
        ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $this->customer->id)
        ->assertJsonPath('data.customer', $this->customer->name);
});

it('refuses a customer that does not exist', function (): void {
    $truck = $this->actingAs($this->admin)->postJson('/api/v1/finance/trucks', [
        'label' => 'Truck 1',
        'plate' => 'NCR 4412',
    ])->json('data.id');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/ledger', [
            'truck_id' => $truck,
            'customer_id' => 'not-a-customer',
            'date' => now()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer_id');
});

it('keeps the day when the customer is deleted', function (): void {
    // Removing a customer must not take a day of income and expenses with it.
    ($this->haul)();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/customers/{$this->customer->id}")
        ->assertSuccessful();

    $row = LedgerEntry::firstOrFail();

    expect($row->exists)->toBeTrue()
        ->and($row->route)->toBe('Cagayan de Oro → Iligan');
});
