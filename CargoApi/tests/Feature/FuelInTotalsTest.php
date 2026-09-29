<?php

declare(strict_types=1);

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * Fills logged in Fuel Expense Monitoring, in the period's Total expenses.
 *
 * They used to be in no total at all: logging a fill at `/fuel` moved that
 * page's budget tile and nothing else, so Profitability, the Summary and Sales
 * all read lower than the receipts. Only an active fill counts — a pending one
 * is a request nobody has approved, and a cancelled one never happened.
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

    $this->vehicle = Vehicle::create([
        'plate' => 'MAR1390', 'model' => 'Isuzu Forward', 'registration_no' => 'R-1',
        'capacity_kg' => 8000, 'status' => 'active',
    ]);
    $this->truck = Truck::create([
        'label' => 'Truck 1', 'plate' => 'MAR1390', 'vehicle_id' => $this->vehicle->id, 'position' => 1,
    ]);

    LedgerEntry::create([
        'truck_id' => $this->truck->id,
        'date' => '2026-07-15',
        'trip_income_cents' => 10_000_000,
        'fuel_cents' => 620_000,
    ]);

    $this->fill = fn (int $cents, string $at, string $status = 'active', ?string $vehicleId = null) => FuelRecord::create([
        'vehicle_id' => $vehicleId ?? $this->vehicle->id,
        'litres' => 100,
        'amount_cents' => $cents,
        'odometer_km' => 184_320,
        'receipt_no' => 'RC-'.random_int(10_000, 99_999),
        'logged_at' => $at,
        'status' => $status,
    ]);

    $this->summary = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->assertOk()
        ->json('data');
});

it('charges an active fill to its truck, inside the Fuel column', function (): void {
    ($this->fill)(450_000, '2026-07-16 08:30:00');

    $summary = ($this->summary)();
    $row = collect($summary['trucks'])->firstWhere('truck.id', $this->truck->id);

    expect($row['fuel_cents'])->toBe(620_000 + 450_000)
        ->and($row['fuel_log_cents'])->toBe(450_000)
        ->and($row['total_expenses_cents'])->toBe(620_000 + 450_000)
        ->and($summary['totals']['fuel_cents'])->toBe(620_000 + 450_000)
        ->and($summary['totals']['total_expenses_cents'])->toBe(620_000 + 450_000)
        ->and($summary['totals']['net_income_cents'])->toBe(10_000_000 - 620_000 - 450_000);
});

it('leaves out pending, cancelled and out-of-window fills', function (): void {
    ($this->fill)(100_000, '2026-07-16 08:30:00', 'pending');
    ($this->fill)(200_000, '2026-07-17 08:30:00', 'cancelled');
    ($this->fill)(300_000, '2026-06-30 23:00:00');

    expect(($this->summary)()['totals']['total_expenses_cents'])->toBe(620_000);
});

it('counts a fill on a vehicle no truck points at as overhead', function (): void {
    $spare = Vehicle::create([
        'plate' => 'ILI 2204', 'model' => 'Hino 300', 'registration_no' => 'R-2',
        'capacity_kg' => 3500, 'status' => 'active',
    ]);
    ($this->fill)(80_000, '2026-08-01 10:00:00', vehicleId: $spare->id);

    $totals = ($this->summary)()['totals'];

    expect($totals['overhead_cents'])->toBe(80_000)
        ->and($totals['fuel_log_cents'])->toBe(0)
        ->and($totals['total_expenses_cents'])->toBe(620_000 + 80_000);
});

it('lists every counted fill behind the tile, and still adds up to it', function (): void {
    $spare = Vehicle::create([
        'plate' => 'ILI 2204', 'model' => 'Hino 300', 'registration_no' => 'R-2',
        'capacity_kg' => 3500, 'status' => 'active',
    ]);
    ($this->fill)(450_000, '2026-07-16 08:30:00');
    ($this->fill)(80_000, '2026-08-01 10:00:00', vehicleId: $spare->id);
    ($this->fill)(100_000, '2026-08-02 10:00:00', 'pending');

    $report = $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/expense-lines?from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->json('data');

    $fills = collect($report['lines'])->where('source', 'fuel_log')->values();

    expect($report['total_cents'])->toBe(($this->summary)()['totals']['total_expenses_cents'])
        ->and($fills)->toHaveCount(2)
        ->and($fills->pluck('truck')->all())->toEqualCanonicalizing(['MAR1390', 'ILI 2204'])
        ->and($fills->pluck('kind')->unique()->all())->toBe(['Fuel']);
});

it('puts a fill in the Sales series on the day it was logged', function (): void {
    ($this->fill)(450_000, '2026-07-20 08:30:00');

    $sales = $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/sales?from=2026-07-01&to=2026-07-31')
        ->assertOk()
        ->json('data');

    expect($sales['series'])->toHaveCount(2)
        ->and($sales['series'][1]['from'])->toBe('2026-07-20')
        ->and($sales['series'][1]['expenses_cents'])->toBe(450_000)
        ->and($sales['totals']['expenses_cents'])->toBe(620_000 + 450_000);
});
