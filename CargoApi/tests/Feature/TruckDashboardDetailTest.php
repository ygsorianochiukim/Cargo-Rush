<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
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

    $names = ['Adidas', 'Nike', 'PMTC', 'Zest', 'Yolo'];
    $this->customers = collect($names)->mapWithKeys(fn (string $n) => [$n => Customer::create(['name' => $n, 'contact' => '0917 555 0101'])]);

    $this->day = fn (Truck $truck, string $date, array $figures) => LedgerEntry::create([
        'truck_id' => $truck->getKey(),
        'date' => $date,
        'route' => 'Cebu',
        'trip_income_cents' => 0,
        'fuel_cents' => 0,
        'driver_salary_cents' => 0,
        'helper_salary_cents' => 0,
        'maintenance_cents' => 0,
        'allowance_cents' => 0,
        ...$figures,
    ]);

    $c = fn (string $name) => $this->customers[$name]->id;

    ($this->day)($this->one, '2026-09-10', ['trip_income_cents' => 4_000_000, 'fuel_cents' => 1_000_000, 'customer_id' => $c('Nike')]);

    ($this->day)($this->one, '2026-10-03', ['trip_income_cents' => 3_400_000, 'fuel_cents' => 500_000, 'customer_id' => $c('PMTC'), 'route' => 'Dumaguete']);
    ($this->day)($this->one, '2026-10-08', ['trip_income_cents' => 2_200_000, 'fuel_cents' => 300_000, 'customer_id' => $c('Adidas')]);
    ($this->day)($this->one, '2026-10-12', ['trip_income_cents' => 1_600_000, 'maintenance_cents' => 200_000, 'customer_id' => $c('Nike')]);
    ($this->day)($this->one, '2026-10-15', ['trip_income_cents' => 1_500_000, 'customer_id' => $c('Zest'), 'route' => 'Dumaguete']);
    ($this->day)($this->one, '2026-10-20', ['trip_income_cents' => 1_200_000, 'customer_id' => $c('Yolo'), 'route' => '']);

    ($this->day)($this->two, '2026-10-05', ['trip_income_cents' => 9_000_000, 'fuel_cents' => 700_000, 'customer_id' => $c('Nike')]);

    $this->dashboard = fn (Truck $truck, string $query = '') => $this->actingAs($this->admin)
        ->getJson("/api/v1/dashboard/trucks/{$truck->id}".$query)
        ->assertOk()
        ->json('data');
});

it('adds up one truck against the month before', function (): void {
    $d = ($this->dashboard)($this->one, '?period=month&date=2026-10-10');
    $kpi = collect($d['kpis'])->keyBy('key');

    expect($d['label'])->toBe('October 2026')
        ->and($d['truck']['id'])->toBe($this->one->id)
        ->and($d['trips'])->toBe(5)
        ->and($kpi['income']['value_cents'])->toBe(9_900_000)
        ->and($kpi['income']['previous_cents'])->toBe(4_000_000)
        ->and($kpi['expenses']['value_cents'])->toBe(1_000_000)
        ->and($kpi['expenses']['previous_cents'])->toBe(1_000_000)
        ->and($kpi['expenses']['change'])->toEqual(0)
        ->and($kpi['net']['value_cents'])->toBe(8_900_000)
        ->and($kpi['margin']['ratio'])->toEqualWithDelta(8_900_000 / 9_900_000, 0.0001)
        ->and($kpi['margin']['previous_ratio'])->toEqualWithDelta(0.75, 0.0001);
});

it('agrees with the truck report for the same month', function (): void {
    $d = ($this->dashboard)($this->one, '?period=month&date=2026-10-10');
    $kpi = collect($d['kpis'])->keyBy('key');

    $row = collect($this->actingAs($this->admin)
        ->getJson('/api/v1/finance/truck-report?period=month&date=2026-10-10')
        ->json('data.trucks'))->firstWhere('truck.id', $this->one->id);

    expect($kpi['income']['value_cents'])->toBe($row['income_cents'])
        ->and($kpi['expenses']['value_cents'])->toBe($row['expenses_cents'])
        ->and($kpi['net']['value_cents'])->toBe($row['net_income_cents']);
});

it('splits income by customer, folding the rest into Others', function (): void {
    $d = ($this->dashboard)($this->one, '?period=month&date=2026-10-10');

    expect($d['customers'])->toBe([
        ['name' => 'PMTC', 'income_cents' => 3_400_000, 'trips' => 1],
        ['name' => 'Adidas', 'income_cents' => 2_200_000, 'trips' => 1],
        ['name' => 'Nike', 'income_cents' => 1_600_000, 'trips' => 1],
        ['name' => 'Others', 'income_cents' => 2_700_000, 'trips' => 2],
    ]);
});

it('ranks the top destinations by trips', function (): void {
    $d = ($this->dashboard)($this->one, '?period=month&date=2026-10-10');

    expect($d['destinations'][0])->toBe(['name' => 'Dumaguete', 'trips' => 2, 'income_cents' => 4_900_000])
        ->and(collect($d['destinations'])->pluck('name')->all())->toBe(['Dumaguete', 'Cebu', 'Not recorded']);
});

it('compares each cost against the month before', function (): void {
    $d = ($this->dashboard)($this->one, '?period=month&date=2026-10-10');
    $costs = collect($d['costs'])->keyBy('kind');

    $fuel = $costs->first(fn (array $row): bool => $row['current_cents'] === 800_000);

    expect($fuel['previous_cents'])->toBe(1_000_000)
        ->and($fuel['variance_cents'])->toBe(-200_000)
        ->and(collect($d['costs'])->sum('current_cents'))->toBe(1_000_000);
});

it('draws twelve months of income and expenses for the truck alone', function (): void {
    $d = ($this->dashboard)($this->one, '?period=month&date=2026-10-10');
    $trend = collect($d['trend'])->keyBy('key');

    expect($d['trend'])->toHaveCount(12)
        ->and($d['trend'][11]['key'])->toBe('2026-10')
        ->and($trend['2026-10']['sales_cents'])->toBe(9_900_000)
        ->and($trend['2026-10']['expenses_cents'])->toBe(1_000_000)
        ->and($trend['2026-09']['sales_cents'])->toBe(4_000_000)
        ->and($trend['2026-08']['sales_cents'])->toBe(0);
});

it('lists every truck for the picker and keeps them apart', function (): void {
    $d = ($this->dashboard)($this->two, '?period=month&date=2026-10-10');

    expect(collect($d['trucks'])->pluck('label')->all())->toBe(['Truck 1', 'Truck 2'])
        ->and(collect($d['kpis'])->firstWhere('key', 'income')['value_cents'])->toBe(9_000_000)
        ->and($d['customers'])->toBe([['name' => 'Nike', 'income_cents' => 9_000_000, 'trips' => 1]]);
});

it('is closed to people who cannot see the books', function (): void {
    $driver = User::create([
        'name' => 'Driver', 'email' => 'driver@test.test',
        'password' => 'password', 'role' => 'driver',
    ]);

    $this->actingAs($driver)
        ->getJson("/api/v1/dashboard/trucks/{$this->one->id}")
        ->assertForbidden();
});
