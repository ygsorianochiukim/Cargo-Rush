<?php

declare(strict_types=1);

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->one = Truck::create(['label' => 'Truck 1', 'plate' => 'MAR1390', 'position' => 1]);
    $this->two = Truck::create(['label' => 'Truck 2', 'plate' => 'ILI2204', 'position' => 2]);

    $this->day = fn (Truck $truck, string $date, array $figures) => LedgerEntry::create([
        'truck_id' => $truck->getKey(),
        'date' => $date,
        'route' => 'CDO – Iligan',
        'trip_income_cents' => 0,
        'fuel_cents' => 0,
        'driver_salary_cents' => 0,
        'helper_salary_cents' => 0,
        'maintenance_cents' => 0,
        'allowance_cents' => 0,
        ...$figures,
    ]);

    ($this->day)($this->one, '2026-10-03', ['trip_income_cents' => 10_000_000, 'fuel_cents' => 500_000]);
    ($this->day)($this->one, '2026-10-20', ['trip_income_cents' => 5_000_000, 'driver_salary_cents' => 100_000]);
    ($this->day)($this->two, '2026-10-05', ['trip_income_cents' => 2_000_000, 'maintenance_cents' => 300_000]);

    $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
        'category_id' => ExpenseCategory::where('key', 'office')->firstOrFail()->id,
        'date' => '2026-10-04',
        'amount_cents' => 120_000,
    ])->assertCreated();

    $this->report = fn (string $period, string $date) => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/truck-report?period={$period}&date={$date}")
        ->assertOk()
        ->json('data');

    $this->truck = fn (array $report, Truck $truck) => collect($report['trucks'])->firstWhere('truck.id', $truck->id);
});

it('shows what each truck earned and spent over a month', function (): void {
    $report = ($this->report)('month', '2026-10-10');

    expect($report['range'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-31']);
    expect($report['label'])->toBe('October 2026');

    $one = ($this->truck)($report, $this->one);
    expect($one['income_cents'])->toBe(15_000_000);
    expect($one['expenses_cents'])->toBe(600_000);
    expect($one['net_income_cents'])->toBe(14_400_000);
    expect(collect($one['lines'])->pluck('kind')->sort()->values()->all())->toBe(['Driver salary', 'Fuel']);

    $two = ($this->truck)($report, $this->two);
    expect($two['income_cents'])->toBe(2_000_000);
    expect($two['expenses_cents'])->toBe(300_000);
    expect($two['by_kind'][0])->toMatchArray(['kind' => 'Maintenance', 'amount_cents' => 300_000]);
});

it('keeps spend that belongs to no truck out of every truck', function (): void {
    $report = ($this->report)('month', '2026-10-10');

    expect($report['unassigned']['expenses_cents'])->toBe(120_000);
    expect($report['unassigned']['lines'])->toHaveCount(1);
    expect($report['totals'])->toMatchArray([
        'income_cents' => 17_000_000,
        'truck_expenses_cents' => 900_000,
        'unassigned_cents' => 120_000,
        'expenses_cents' => 1_020_000,
        'net_income_cents' => 15_980_000,
    ]);
});

it('narrows to the pay cutoff the date falls in', function (): void {
    $report = ($this->report)('cutoff', '2026-10-10');

    expect($report['range'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-15']);
    expect($report['previous'])->toBe('2026-09-30');
    expect($report['next'])->toBe('2026-10-16');

    $one = ($this->truck)($report, $this->one);
    expect($one['income_cents'])->toBe(10_000_000);
    expect($one['expenses_cents'])->toBe(500_000);

    $second = ($this->report)('cutoff', $report['next']);
    expect($second['range'])->toBe(['from' => '2026-10-16', 'to' => '2026-10-31']);
    expect(($this->truck)($second, $this->one)['income_cents'])->toBe(5_000_000);
});

it('follows the company\'s own cutoff days', function (): void {
    $this->company->forceFill(['payroll_cutoff_days' => [10, 25]])->save();
    $this->admin = $this->admin->fresh();

    $report = ($this->report)('cutoff', '2026-10-20');

    expect($report['range'])->toBe(['from' => '2026-10-11', 'to' => '2026-10-25']);
    expect(($this->truck)($report, $this->one)['income_cents'])->toBe(5_000_000);
});

it('narrows to a single day', function (): void {
    $report = ($this->report)('day', '2026-10-20');

    expect($report['range'])->toBe(['from' => '2026-10-20', 'to' => '2026-10-20']);
    expect(($this->truck)($report, $this->one)['expenses_cents'])->toBe(100_000);
    expect(($this->truck)($report, $this->two)['income_cents'])->toBe(0);
    expect($report['unassigned']['expenses_cents'])->toBe(0);
});

it('agrees with Profitability on every truck', function (): void {
    $report = ($this->report)('month', '2026-10-10');

    $pnl = collect($this->actingAs($this->admin)
        ->getJson('/api/v1/finance/profitability?from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->json('data.trucks'))->keyBy('truck.id');

    foreach ($report['trucks'] as $row) {
        expect($row['income_cents'])->toBe($pnl[$row['truck']['id']]['trip_income_cents']);
        expect($row['expenses_cents'])->toBe($pnl[$row['truck']['id']]['total_expenses_cents']);
    }
});

it('is a finance report, closed to a dispatcher', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Dispatcher]))
        ->getJson('/api/v1/finance/truck-report')
        ->assertForbidden();
});
