<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
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

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Delivered->value, 'cargo' => 'Retail goods'])
        ->assertOk();

    expect(Invoice::query()->where('trip_id', $id)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('trip_id', $id)->count())->toBe(1);
});

it('leaves the invoice of a past trip unpaid unless told otherwise', function (): void {
    $id = ($this->enter)(['payment_status' => 'unpaid'])
        ->assertCreated()
        ->assertJsonPath('data.invoice.paid_cents', 0)
        ->json('data.id');

    expect(Payment::query()->count())->toBe(0)
        ->and(Invoice::query()->where('trip_id', $id)->firstOrFail()->status)->not->toBe(StatusValue::Paid);
});

it('records a past trip as paid in full, on the day it was paid', function (): void {
    $id = ($this->enter)([
        'payment_status' => 'paid',
        'paid_on' => '2026-06-20',
        'payment_method' => 'cash',
        'payment_reference' => 'OR-1182',
    ])
        ->assertCreated()
        ->assertJsonPath('data.invoice.status', StatusValue::Paid->value)
        ->assertJsonPath('data.invoice.balance_cents', 0)
        ->json('data.id');

    $invoice = Invoice::query()->where('trip_id', $id)->firstOrFail();
    $payment = Payment::query()->firstOrFail();

    expect($payment->amount_cents)->toBe($invoice->dueCents())
        ->and($payment->paid_on->toDateString())->toBe('2026-06-20')
        ->and($payment->method)->toBe('cash')
        ->and($payment->reference)->toBe('OR-1182')
        ->and($invoice->paid_at->toDateString())->toBe('2026-06-20');
});

it('dates the payment to the delivery when no paid date is given', function (): void {
    ($this->enter)(['payment_status' => 'paid', 'delivered_at' => '2026-06-11 09:00:00'])->assertCreated();

    expect(Payment::query()->firstOrFail()->paid_on->toDateString())->toBe('2026-06-11');
});

it('records a part payment and the rest later from the trip', function (): void {
    $id = ($this->enter)(['payment_status' => 'partial', 'paid_cents' => 200_000])
        ->assertCreated()
        ->assertJsonPath('data.invoice.status', StatusValue::Partial->value)
        ->assertJsonPath('data.invoice.paid_cents', 200_000)
        ->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['payment_status' => 'paid'])
        ->assertOk()
        ->assertJsonPath('data.invoice.status', StatusValue::Paid->value);

    expect(Payment::query()->count())->toBe(2);
});

it('needs the amount of a part payment', function (): void {
    ($this->enter)(['payment_status' => 'partial'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('paid_cents');
});

it('refuses a payment on a trip that has not been delivered', function (): void {
    ($this->enter)(['status' => StatusValue::Assigned->value, 'payment_status' => 'paid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_status');
});

it('refuses a payment on a past trip with no customer to bill', function (): void {
    ($this->enter)(['customer_id' => null, 'payment_status' => 'paid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_status');

    expect(Trip::query()->count())->toBe(0);
});
