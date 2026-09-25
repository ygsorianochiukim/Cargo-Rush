<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * The transactions behind the Summary's Total expenses tile.
 *
 * The one thing that matters is that the rows add up to the tile. A breakdown
 * that disagrees with the figure it breaks down tells the office one of the two
 * is wrong and gives them no way to find out which, so every source the total
 * draws on is filed here once and the sum is checked against the Summary's own.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'MAR1390', 'position' => 1]);
    $this->supplier = Customer::create(['name' => 'Northern Mindanao Haulage', 'contact' => '0917 222 0044']);

    $this->day = fn (string $date, array $costs = []) => LedgerEntry::create([
        'truck_id' => $this->truck->getKey(),
        'date' => $date,
        'route' => 'CDO – Iligan',
        'trip_income_cents' => 10_000_000,
        'fuel_cents' => 0,
        'driver_salary_cents' => 0,
        'helper_salary_cents' => 0,
        'maintenance_cents' => 0,
        'allowance_cents' => 0,
        ...$costs,
    ]);

    $this->spend = fn (string $date, int $cents, array $extra = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/expenses', [
            'category_id' => ExpenseCategory::where('key', 'office')->firstOrFail()->id,
            'date' => $date,
            'amount_cents' => $cents,
            ...$extra,
        ])->assertCreated();

    $this->paidBill = function (int $cents, string $paidOn): void {
        $bill = $this->actingAs($this->admin)->postJson('/api/v1/billing', [
            'payee' => 'Northern Mindanao Haulage',
            'customer_id' => $this->supplier->id,
            'issued_at' => '2026-07-01',
            'due_at' => '2026-07-01',
            'amount_cents' => $cents,
            'direction' => 'payable',
        ])->assertCreated()->json('data');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->supplier->id,
            'direction' => 'payable',
            'amount_cents' => $cents,
            'paid_on' => $paidOn,
            'method' => 'bank_transfer',
            'allocations' => [['invoice_id' => $bill['id'], 'amount_cents' => $cents]],
        ])->assertCreated();
    };

    $this->payout = fn (int $cents, string $on) => WalletEntry::create([
        'trucker_id' => Trucker::factory()->approved()->create(['name' => 'Boyet Aquino'])->getKey(),
        'kind' => WalletEntryKind::Payout->value,
        'status' => StatusValue::Paid->value,
        'amount_cents' => -$cents,
        'occurred_on' => $on,
    ]);

    $this->lines = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/expense-lines?from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->json('data');

    $this->quarterTotal = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->assertOk()
        ->json('data.totals.total_expenses_cents');
});

it('adds up to the Summary total, with every source in it', function (): void {
    ($this->day)('2026-07-15', ['fuel_cents' => 620_000, 'driver_salary_cents' => 150_000]);
    ($this->spend)('2026-08-02', 1_200_000);
    ($this->paidBill)(640_000, '2026-08-20');
    ($this->payout)(380_000, '2026-09-05');

    $report = ($this->lines)();

    expect($report['total_cents'])->toBe(($this->quarterTotal)())
        ->and($report['total_cents'])->toBe(620_000 + 150_000 + 1_200_000 + 640_000 + 380_000)
        ->and(array_column($report['lines'], 'source'))
        ->toBe(['trucker_payout', 'supplier_bill', 'expense', 'sheet', 'sheet']);
});

it('splits a sheet day into one row per cost column, and skips the empty ones', function (): void {
    ($this->day)('2026-07-15', ['fuel_cents' => 620_000, 'allowance_cents' => 50_000]);

    $lines = ($this->lines)()['lines'];

    expect(array_column($lines, 'kind'))->toEqualCanonicalizing(['Fuel', 'Allowance'])
        ->and($lines[0]['truck'])->toBe('MAR1390')
        ->and($lines[0]['description'])->toBe('CDO – Iligan');
});

it('leaves out what the total leaves out', function (): void {
    // Outside the window, cancelled, and a bill raised but never paid.
    ($this->day)('2026-06-30', ['fuel_cents' => 500_000]);
    ($this->spend)('2026-10-01', 90_000);
    ($this->spend)('2026-08-01', 70_000, ['status' => 'cancelled']);
    $this->actingAs($this->admin)->postJson('/api/v1/billing', [
        'payee' => 'Northern Mindanao Haulage',
        'customer_id' => $this->supplier->id,
        'issued_at' => '2026-07-01',
        'due_at' => '2026-07-01',
        'amount_cents' => 640_000,
        'direction' => 'payable',
    ])->assertCreated();

    $report = ($this->lines)();

    expect($report['lines'])->toBe([])
        ->and($report['total_cents'])->toBe(0)
        ->and(($this->quarterTotal)())->toBe(0);
});

it('is closed to someone without finance access', function (): void {
    $driver = User::create([
        'name' => 'Driver', 'email' => 'driver@test.test',
        'password' => 'password', 'role' => 'driver',
    ]);

    $this->actingAs($driver)->getJson('/api/v1/finance/expense-lines')->assertForbidden();
});
