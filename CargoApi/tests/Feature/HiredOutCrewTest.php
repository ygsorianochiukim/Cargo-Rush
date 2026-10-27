<?php

declare(strict_types=1);

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Vehicle\Models\VehicleDriverAssignment;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->driver = function (string $basis = 'per_trip', int $rate = 150_000): array {
        $driver = $this->actingAs($this->admin)->postJson('/api/v1/drivers', [
            'name' => 'Yvert Soriano',
            'licence_no' => 'HIRE-OUT-1',
            'licence_expiry' => '2029-08-31',
        ])->assertCreated()->json('data');

        payContract(Employee::findOrFail($driver['employee_id']), $basis, $rate, '2026-01-05');

        return $driver;
    };

    $this->truck = function (array $overrides = []): array {
        static $n = 0;

        return $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
            'plate' => 'HO '.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-HO-'.$n,
            'capacity_kg' => 15_000,
            ...$overrides,
        ])->assertCreated()->json('data');
    };

    $this->assign = fn (array $truck, ?string $driverId, string $on) => $this->travelTo(
        Carbon::parse($on.' 09:00:00'),
        fn () => $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$truck['id']}", ['driver_id' => $driverId])
            ->assertOk(),
    );

    $this->haul = function (string $date, string $driverId): Trip {
        static $n = 0;

        return Trip::create([
            'reference' => 'HO-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'origin' => 'Davao City',
            'destination' => 'Tagum City',
            'cargo' => 'Assorted',
            'weight_kg' => 2000,
            'driver_id' => $driverId,
            'status' => 'delivered',
            'scheduled_at' => $date.' 07:00:00',
        ]);
    };

    $this->open = fn () => $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
        'scope' => 'crew',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-15',
        'pay_date' => '2026-09-15',
    ])->assertCreated()->json('data');
});

describe('the record of who held the keys', function (): void {
    it('opens a stretch when a driver is assigned and closes it the day before they are taken off', function (): void {
        $driver = ($this->driver)();
        $truck = ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000]);

        ($this->assign)($truck, $driver['id'], '2026-09-06');
        ($this->assign)($truck, null, '2026-09-11');

        $spans = VehicleDriverAssignment::query()->where('driver_id', $driver['id'])->get();

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->from_date->toDateString())->toBe('2026-09-06')
            ->and($spans[0]->to_date->toDateString())->toBe('2026-09-10');
    });

    it('starts a stretch from the day a truck is added with a driver on it', function (): void {
        $driver = ($this->driver)();

        $truck = $this->travelTo(
            Carbon::parse('2026-09-03 09:00:00'),
            fn () => ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000, 'driver_id' => $driver['id']]),
        );

        $span = VehicleDriverAssignment::query()->where('vehicle_id', $truck['id'])->firstOrFail();

        expect($span->from_date->toDateString())->toBe('2026-09-03')
            ->and($span->to_date)->toBeNull();
    });

    it('swaps one stretch for another when the keys change hands', function (): void {
        $first = ($this->driver)();
        $second = $this->actingAs($this->admin)->postJson('/api/v1/drivers', [
            'name' => 'Jun Santos', 'licence_no' => 'HIRE-OUT-2', 'licence_expiry' => '2029-08-31',
        ])->assertCreated()->json('data');
        $truck = ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000]);

        ($this->assign)($truck, $first['id'], '2026-09-02');
        ($this->assign)($truck, $second['id'], '2026-09-08');

        $spans = VehicleDriverAssignment::query()->where('vehicle_id', $truck['id'])->orderBy('from_date')->get();

        expect($spans)->toHaveCount(2)
            ->and($spans[0]->driver_id)->toBe($first['id'])
            ->and($spans[0]->to_date->toDateString())->toBe('2026-09-07')
            ->and($spans[1]->driver_id)->toBe($second['id'])
            ->and($spans[1]->to_date)->toBeNull();
    });
});

describe('pay while assigned to a hired truck', function (): void {
    it('pays the hauls before and after the stretch, and not the ones inside it', function (): void {
        $driver = ($this->driver)();
        $truck = ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000]);

        ($this->haul)('2026-09-03', $driver['id']);
        $inside = ($this->haul)('2026-09-08', $driver['id']);
        ($this->haul)('2026-09-13', $driver['id']);

        ($this->assign)($truck, $driver['id'], '2026-09-06');
        ($this->assign)($truck, null, '2026-09-11');

        $run = ($this->open)();
        $line = $run['lines'][0];

        expect($run['staff_count'])->toBe(1)
            ->and($line['trips'])->toBe(2)
            ->and($line['basic_cents'])->toBe(300_000)
            ->and(array_column($line['trip_details'], 'reference'))->not->toContain($inside->reference)
            ->and($line['hired_out'])->toHaveCount(1)
            ->and($line['hired_out'][0]['plate'])->toBe($truck['plate'])
            ->and($line['hired_out'][0]['arrangement'])->toBe('rented')
            ->and($line['hired_out'][0]['from'])->toBe('2026-09-06')
            ->and($line['hired_out'][0]['to'])->toBe('2026-09-10');
    });

    it('leaves a driver off the run when they held a hired truck the whole cutoff', function (): void {
        $driver = ($this->driver)();
        $truck = ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000]);

        ($this->haul)('2026-09-03', $driver['id']);
        ($this->haul)('2026-09-13', $driver['id']);
        ($this->assign)($truck, $driver['id'], '2026-08-20');

        expect(($this->open)()['staff_count'])->toBe(0);
    });

    it('counts a sub-contracted truck the same way as a rented one', function (): void {
        $owner = Trucker::factory()->approved()->create(['name' => 'Ortega Trucking', 'licence_no' => null]);
        $driver = ($this->driver)();
        $truck = ($this->truck)(['arrangement' => 'subcontracted', 'owner_trucker_id' => $owner->id]);

        ($this->haul)('2026-09-03', $driver['id']);
        ($this->haul)('2026-09-08', $driver['id']);
        ($this->assign)($truck, $driver['id'], '2026-09-05');

        $line = ($this->open)()['lines'][0];

        expect($line['trips'])->toBe(1)
            ->and($line['hired_out'][0]['arrangement'])->toBe('subcontracted')
            ->and($line['hired_out'][0]['to'])->toBe('2026-09-15');
    });

    it('pays everything when the truck they hold is the fleet’s own', function (): void {
        $driver = ($this->driver)();
        $truck = ($this->truck)(['arrangement' => 'owned']);

        ($this->haul)('2026-09-03', $driver['id']);
        ($this->haul)('2026-09-08', $driver['id']);
        ($this->assign)($truck, $driver['id'], '2026-08-20');

        $line = ($this->open)()['lines'][0];

        expect($line['trips'])->toBe(2)
            ->and($line['hired_out'])->toBe([]);
    });

    it('keeps sheet days inside the stretch off a daily-paid driver', function (): void {
        $driver = ($this->driver)('daily', 80_000);
        $truck = ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000]);
        $unit = Truck::create(['label' => 'Truck 7', 'plate' => 'NCR 7007', 'position' => 7]);

        foreach (['2026-09-03', '2026-09-08', '2026-09-13'] as $date) {
            LedgerEntry::create([
                'truck_id' => $unit->id, 'date' => $date, 'driver_id' => $driver['id'],
                'trip_income_cents' => 500_000, 'driver_salary_cents' => 80_000,
            ]);
        }

        ($this->assign)($truck, $driver['id'], '2026-09-06');
        ($this->assign)($truck, null, '2026-09-11');

        $line = ($this->open)()['lines'][0];

        expect($line['days_worked'])->toBe(2)
            ->and($line['basic_cents'])->toBe(160_000);
    });

    it('pays again once the assignment is taken off, from that day', function (): void {
        $driver = ($this->driver)();
        $truck = ($this->truck)(['arrangement' => 'rented', 'rent_cents' => 5_000_000]);

        ($this->assign)($truck, $driver['id'], '2026-08-20');
        ($this->assign)($truck, null, '2026-09-10');

        ($this->haul)('2026-09-09', $driver['id']);
        ($this->haul)('2026-09-10', $driver['id']);

        $line = ($this->open)()['lines'][0];

        expect($line['trips'])->toBe(1)
            ->and($line['hired_out'][0]['to'])->toBe('2026-09-09');
    });
});
