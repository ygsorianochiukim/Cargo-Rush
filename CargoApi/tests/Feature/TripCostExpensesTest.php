<?php

declare(strict_types=1);

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
        'delivered_at' => '2026-06-10 15:30:00',
        'status' => StatusValue::Delivered->value,
        ...$overrides,
    ])->assertCreated()->json('data.id');

    $this->sheet = fn (string $tripId): LedgerEntry => LedgerEntry::query()->where('trip_id', $tripId)->firstOrFail();

    $this->lines = fn () => collect(
        $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/expense-lines?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->json('data.lines'),
    )->where('source', 'trip')->values();
});

it('puts a delivered trip\'s allowances on the expenses', function (): void {
    $id = ($this->enter)(['driver_allowance_cents' => 50_000, 'helper_allowance_cents' => 30_000]);

    expect(($this->sheet)($id)->allowance_cents)->toBe(80_000);

    $lines = ($this->lines)();

    expect($lines->pluck('amount_cents', 'kind')->all())
        ->toBe(['Driver allowance' => 50_000, 'Helper allowance' => 30_000])
        ->and($lines->first()['date'])->toBe('2026-06-10');
});

it('puts the fuel encoded after the trip on the expenses', function (): void {
    $id = ($this->enter)();
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 40, 'fuel_cost_cents' => 240_000])
        ->assertOk();

    expect(($this->sheet)($id)->fuel_cents)->toBe(240_000)
        ->and(($this->lines)()->pluck('amount_cents', 'kind')->all())->toBe(['Fuel' => 240_000]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 40, 'fuel_cost_cents' => 200_000])
        ->assertOk();

    expect(($this->sheet)($id)->fuel_cents)->toBe(200_000)
        ->and(($this->lines)()->sum('amount_cents'))->toBe(200_000);
});

it('does not count the same cost twice on the expenses list', function (): void {
    $id = ($this->enter)(['driver_allowance_cents' => 50_000]);
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_cost_cents' => 100_000, 'fuel_litres' => null])
        ->assertOk();

    $all = collect($this->actingAs($this->admin)
        ->getJson('/api/v1/finance/expense-lines?from=2026-06-01&to=2026-06-30')
        ->json('data.lines'));

    expect($all->whereIn('kind', ['Fuel', 'Allowance', 'Driver allowance'])->sum('amount_cents'))->toBe(150_000);
});

it('follows a change to the allowance on a delivered trip', function (): void {
    $id = ($this->enter)(['driver_allowance_cents' => 50_000]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['driver_allowance_cents' => 70_000])
        ->assertOk();

    expect(($this->sheet)($id)->allowance_cents)->toBe(70_000)
        ->and(($this->lines)()->sum('amount_cents'))->toBe(70_000);
});

it('takes the costs back off when the trip is reopened', function (): void {
    $id = ($this->enter)(['driver_allowance_cents' => 50_000]);
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_cost_cents' => 100_000, 'fuel_litres' => null])
        ->assertOk();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Cancelled->value])
        ->assertOk();

    $sheet = ($this->sheet)($id);

    expect($sheet->allowance_cents)->toBe(0)
        ->and($sheet->fuel_cents)->toBe(0)
        ->and(($this->lines)())->toHaveCount(0)
        ->and($log->refresh()->posted_truck_id)->toBeNull();
});

it('keeps the sheet from overwriting allowances that came from a trip', function (): void {
    $id = ($this->enter)(['driver_allowance_cents' => 50_000]);
    $sheet = ($this->sheet)($id);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/ledger/{$sheet->id}", ['allowance_cents' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allowance_cents');
});

it('posts the costs of trips delivered before posting existed', function (): void {
    $id = ($this->enter)();
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    $log->forceFill(['fuel_cost_cents' => 90_000])->saveQuietly();
    Trip::whereKey($id)->update(['driver_allowance_cents' => 40_000]);

    expect(($this->sheet)($id)->fuel_cents)->toBe(0);

    (require database_path('migrations/2026_10_16_000400_post_existing_trip_costs.php'))->up();

    $sheet = ($this->sheet)($id);

    expect($sheet->fuel_cents)->toBe(90_000)
        ->and($sheet->allowance_cents)->toBe(40_000)
        ->and(($this->lines)()->sum('amount_cents'))->toBe(130_000);
});

it('lists fuel encoded after trips on fuel monitoring', function (): void {
    $id = ($this->enter)();
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 35.5, 'fuel_cost_cents' => 210_000])
        ->assertOk();

    $this->actingAs($this->admin)->getJson('/api/v1/fuel/trips')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trip_id', $id)
        ->assertJsonPath('data.0.vehicle_plate', 'NCR 4412')
        ->assertJsonPath('data.0.litres', 35.5)
        ->assertJsonPath('data.0.amount_cents', 210_000)
        ->assertJsonPath('data.0.posted_on', '2026-06-10');
});

it('counts trip fuel in the day\'s fuel spend', function (): void {
    $id = ($this->enter)();
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    $before = $this->actingAs($this->admin)->getJson('/api/v1/fuel/budget?date=2026-06-10')->json('data.spent_today_cents');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => null, 'fuel_cost_cents' => 210_000])
        ->assertOk();

    $this->actingAs($this->admin)->getJson('/api/v1/fuel/budget?date=2026-06-10')
        ->assertOk()
        ->assertJsonPath('data.spent_today_cents', $before + 210_000)
        ->assertJsonPath('data.trip_fuel_today_cents', 210_000)
        ->assertJsonPath('data.trip_fuel_month_cents', 210_000);
});
