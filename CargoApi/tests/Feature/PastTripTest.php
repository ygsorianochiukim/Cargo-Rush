<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * Entering a trip that already happened, as Delivered.
 *
 * The office catching up on old work: the trip goes through the same delivery
 * a driver's hand-off does — the delivery log, the day's sheet income, the
 * customer's invoice — dated the day it was delivered, not the day it was
 * typed in. Only for the past; a future trip is delivered by driving it.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::firstOrFail();

    $this->enter = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Assorted retail',
        'weight_kg' => 2400,
        'price_cents' => 500_000,
        'customer_id' => $this->customer->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'scheduled_at' => '2026-06-10 08:00:00',
        'status' => StatusValue::Delivered->value,
        ...$overrides,
    ]);
});

it('books a past trip as delivered, with the money dated the day it happened', function (): void {
    $before = $this->driver->trips_completed;

    $id = ($this->enter)(['delivered_at' => '2026-06-10 15:30:00', 'receiver_name' => 'R. Uy'])
        ->assertCreated()
        ->assertJsonPath('data.status', StatusValue::Delivered->value)
        ->json('data.id');

    $log = DeliveryLog::query()->where('trip_id', $id)->firstOrFail();
    $invoice = Invoice::query()->where('trip_id', $id)->firstOrFail();
    $sheet = LedgerEntry::query()->where('trip_id', $id)->firstOrFail();

    expect($log->delivered_at->toDateString())->toBe('2026-06-10')
        ->and($log->receiver_name)->toBe('R. Uy')
        ->and($invoice->issued_at->toDateString())->toBe('2026-06-10')
        ->and($invoice->net_amount_cents)->toBe(500_000)
        ->and($sheet->date->toDateString())->toBe('2026-06-10')
        ->and($sheet->trip_income_cents)->toBe(500_000)
        ->and($this->driver->refresh()->trips_completed)->toBe($before + 1)
        ->and(Trip::find($id)->isBilled())->toBeTrue();
});

it('dates it to the scheduled time when no delivery time is given', function (): void {
    $id = ($this->enter)()->assertCreated()->json('data.id');

    expect(DeliveryLog::query()->where('trip_id', $id)->firstOrFail()->delivered_at->toDateString())
        ->toBe('2026-06-10');
});

it('refuses a trip scheduled in the future', function (): void {
    ($this->enter)(['scheduled_at' => now()->addDay()->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('refuses a delivery before the trip was scheduled', function (): void {
    ($this->enter)(['delivered_at' => '2026-06-09 12:00:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('delivered_at');
});

it('marks an existing trip that already happened as delivered, once', function (): void {
    $id = ($this->enter)(['status' => StatusValue::Assigned->value])->assertCreated()->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Delivered->value])
        ->assertOk()
        ->assertJsonPath('data.status', StatusValue::Delivered->value);

    // Saving it again is an edit, not a second delivery: one invoice, one row.
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Delivered->value, 'cargo' => 'Retail goods'])
        ->assertOk();

    expect(Invoice::query()->where('trip_id', $id)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('trip_id', $id)->count())->toBe(1);
});
