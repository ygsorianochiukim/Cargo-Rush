<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Assorted retail',
        'weight_kg' => 2400,
        'price_cents' => 500_000,
        'customer_id' => Customer::firstOrFail()->id,
        'driver_id' => Driver::where('name', 'Marco Reyes')->firstOrFail()->id,
        'vehicle_id' => Vehicle::where('plate', 'NCR 4412')->firstOrFail()->id,
        'driver_allowance_cents' => 30_000,
        'helper_allowance_cents' => 10_000,
        'toll_cents' => 35_000,
        'scheduled_at' => '2026-06-10 08:00:00',
        'delivered_at' => '2026-06-10 15:30:00',
        'status' => StatusValue::Delivered->value,
        ...$overrides,
    ])->assertCreated()->json('data.id');

    $this->expense = fn (string $tripId, int $cents) => $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
        'category_id' => ExpenseCategory::where('key', 'food')->firstOrFail()->id,
        'trip_id' => $tripId,
        'date' => '2026-06-10',
        'amount_cents' => $cents,
        'payee' => 'Aling Nena',
    ])->assertCreated();

    $this->costs = fn (string $tripId) => $this->actingAs($this->admin)
        ->getJson("/api/v1/trips/{$tripId}/costs")
        ->assertOk()
        ->json('data');
});

it('nets a delivered trip\'s income against everything it cost', function (): void {
    $id = ($this->book)();

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/delivery-logs/'.DeliveryLog::where('trip_id', $id)->value('id').'/fuel', [
            'fuel_litres' => 20, 'fuel_cost_cents' => 120_000,
        ])->assertOk();

    ($this->expense)($id, 20_000);

    $costs = ($this->costs)($id);

    expect($costs['income_cents'])->toBe(500_000)
        ->and(collect($costs['lines'])->pluck('amount_cents', 'kind')->all())->toBe([
            'Fuel' => 120_000,
            'Driver allowance' => 30_000,
            'Helper allowance' => 10_000,
            'Toll' => 35_000,
            'Food' => 20_000,
        ])
        ->and($costs['costs_cents'])->toBe(215_000)
        ->and($costs['net_cents'])->toBe(285_000)
        ->and($costs['final'])->toBeTrue();
});

it('counts the toll once, before and after it is posted', function (): void {
    $pending = ($this->book)(['status' => StatusValue::Pending->value, 'delivered_at' => null]);
    $delivered = ($this->book)();

    $toll = fn (string $id) => collect(($this->costs)($id)['lines'])->where('kind', 'Toll')->sum('amount_cents');

    expect($toll($pending))->toBe(35_000)
        ->and($toll($delivered))->toBe(35_000)
        ->and(($this->costs)($pending)['final'])->toBeFalse();
});

it('leaves out a cancelled expense', function (): void {
    $id = ($this->book)(['toll_cents' => null]);
    $expense = ($this->expense)($id, 20_000)->json('data.id');

    $this->actingAs($this->admin)->patchJson("/api/v1/expenses/{$expense}", ['status' => 'cancelled'])->assertOk();

    expect(($this->costs)($id)['costs_cents'])->toBe(40_000);
});

it('nets a trucker\'s trip on our commission, without the crew costs the trucker carries', function (): void {
    $id = ($this->book)(['status' => StatusValue::Pending->value, 'delivered_at' => null, 'toll_cents' => null]);

    Trip::whereKey($id)->update([
        'trucker_id' => Trucker::factory()->approved()->create()->id,
        'commission_cents' => 60_000,
    ]);

    ($this->expense)($id, 5_000);

    $costs = ($this->costs)($id);

    expect($costs['hauled_by'])->toBe('trucker')
        ->and($costs['income_cents'])->toBe(60_000)
        ->and(collect($costs['lines'])->pluck('kind')->all())->toBe(['Food'])
        ->and($costs['net_cents'])->toBe(55_000);
});

it('earns nothing on a cancelled trip, but still counts what was spent on it', function (): void {
    $id = ($this->book)(['status' => StatusValue::Pending->value, 'delivered_at' => null, 'toll_cents' => null]);
    ($this->expense)($id, 20_000);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Cancelled->value])
        ->assertOk();

    $costs = ($this->costs)($id);

    expect($costs['income_cents'])->toBe(0)
        ->and($costs['net_cents'])->toBe(-$costs['costs_cents']);
});

it('shows each trip\'s net on the trip list', function (): void {
    $id = ($this->book)();
    ($this->expense)($id, 20_000);

    $row = collect($this->actingAs($this->admin)->getJson('/api/v1/trips')->assertOk()->json('data'))
        ->firstWhere('id', $id);

    expect($row['trip_income_cents'])->toBe(500_000)
        ->and($row['costs_cents'])->toBe(95_000)
        ->and($row['net_cents'])->toBe(405_000);
});

it('keeps the money to people who can see finance or expenses', function (): void {
    $id = ($this->book)();
    $dispatcher = User::factory()->create(['role' => Role::Dispatcher]);

    $this->actingAs($dispatcher)->getJson("/api/v1/trips/{$id}/costs")->assertForbidden();

    $row = collect($this->actingAs($dispatcher)->getJson('/api/v1/trips')->assertOk()->json('data'))
        ->firstWhere('id', $id);

    expect($row)->not->toHaveKey('net_cents');
});
