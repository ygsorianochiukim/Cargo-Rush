<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
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

    $this->travelTo(Carbon\Carbon::parse('2026-06-20 10:00:00'));

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro', 'destination' => 'Iligan', 'cargo' => 'Retail', 'weight_kg' => 2400,
        'price_cents' => 500_000,
        'customer_id' => Customer::firstOrFail()->id,
        'driver_id' => Driver::where('name', 'Marco Reyes')->firstOrFail()->id,
        'vehicle_id' => Vehicle::where('plate', 'NCR 4412')->firstOrFail()->id,
        'driver_allowance_cents' => 30_000,
        'scheduled_at' => '2026-06-10 08:00:00',
        'delivered_at' => '2026-06-10 15:30:00',
        'status' => StatusValue::Delivered->value,
    ])->assertCreated();

    $this->card = PaymentSource::create(['name' => 'BPI Mastercard', 'kind' => 'credit_card']);

    $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
        'category_id' => ExpenseCategory::where('key', 'office')->firstOrFail()->id,
        'date' => '2026-06-12',
        'amount_cents' => 80_000,
        'payment_source_id' => $this->card->id,
    ])->assertCreated();

    Invoice::create([
        'direction' => 'payable', 'payee' => 'Davao Lubes',
        'issued_at' => '2026-06-01', 'due_at' => '2026-06-05', 'amount_cents' => 70_000, 'status' => 'pending',
    ]);

    $this->dashboard = fn (string $query = '') => $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/business'.$query)
        ->assertOk()
        ->json('data');
});

it('agrees with the summary on income, expenses and net for the month', function (): void {
    $dash = ($this->dashboard)('?period=month&date=2026-06-15');

    $totals = $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/profitability?from=2026-06-01&to=2026-06-30')
        ->json('data.totals');

    $kpi = collect($dash['kpis'])->keyBy('key');

    expect($dash['label'])->toBe('June 2026')
        ->and($kpi['income']['value_cents'])->toBe($totals['total_income_cents'])
        ->and($kpi['expenses']['value_cents'])->toBe($totals['total_expenses_cents'])
        ->and($kpi['net']['value_cents'])->toBe($totals['net_income_cents'])
        ->and($kpi['income']['previous_cents'])->toBe(0)
        ->and($kpi['income']['change'])->toBeNull();
});

it('shows what is owed both ways, aged', function (): void {
    $dash = ($this->dashboard)('?period=month&date=2026-06-15');

    expect($dash['receivables']['outstanding_cents'])->toBeGreaterThan(0)
        ->and(collect($dash['receivables']['aging'])->sum('cents'))->toBe($dash['receivables']['outstanding_cents'])
        ->and(collect($dash['payables']['bill_aging'])->firstWhere('key', '1_30')['cents'])->toBe(70_000)
        ->and(collect($dash['payables']['groups'])->pluck('key'))->toContain('supplier_bills');
});

it('charts twelve months and splits spend by kind and by card', function (): void {
    $dash = ($this->dashboard)('?period=month&date=2026-06-15');

    expect($dash['trend'])->toHaveCount(12)
        ->and(end($dash['trend'])['key'])->toBe('2026-06')
        ->and(collect($dash['expense_mix'])->pluck('kind'))->toContain('Office & Admin')
        ->and($dash['paid_from'][0])->toMatchArray(['name' => 'BPI Mastercard', 'amount_cents' => 80_000]);
});

it('reads a quarter and a year', function (): void {
    expect(($this->dashboard)('?period=quarter&date=2026-06-15')['range'])->toBe(['from' => '2026-04-01', 'to' => '2026-06-30'])
        ->and(($this->dashboard)('?period=year&date=2026-06-15')['label'])->toBe('2026');
});

it('lists every truck\'s month on the truck dashboard', function (): void {
    $trucks = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/trucks?period=month&date=2026-06-15')
        ->assertOk()
        ->json('data');

    $row = collect($trucks['trucks'])->firstWhere('truck.plate', 'NCR 4412');

    expect($row['trips'])->toBe(1)
        ->and($row['income_cents'])->toBe(500_000)
        ->and($trucks['totals']['trips'])->toBe(1);
});

it('keeps the money dashboards to finance', function (): void {
    $dispatcher = User::factory()->create(['role' => Role::Dispatcher]);

    $this->actingAs($dispatcher)->getJson('/api/v1/dashboard/business')->assertForbidden();
    $this->actingAs($dispatcher)->getJson('/api/v1/dashboard/trucks')->assertForbidden();
});
