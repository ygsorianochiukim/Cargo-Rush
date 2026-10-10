<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'NCR 4412', 'position' => 1]);

    $this->hireDriver = function (array $overrides = []) {
        $driver = Driver::create([
            'name' => $overrides['name'] ?? 'Marco Villanueva',
            'licence_no' => $overrides['licence_no'] ?? 'N01-23-456789',
            'licence_expiry' => '2029-08-31',
            'status' => 'available',
        ]);

        $employee = Employee::create([
            'employee_no' => $overrides['employee_no'] ?? 'EMP-9001',
            'first_name' => $overrides['first_name'] ?? 'Marco',
            'last_name' => $overrides['last_name'] ?? 'Villanueva',
            'position' => 'Driver',
            'contact' => '0917 555 0123',
            'hired_on' => '2026-01-05',
            'status' => 'active',
            'driver_id' => $driver->id,
            'sss_enrolled' => true,
            'philhealth_enrolled' => true,
            'pagibig_enrolled' => true,
        ]);

        if (($overrides['amount_cents'] ?? 150_000) > 0) {
            payContract(
                $employee,
                $overrides['pay_basis'] ?? 'per_trip',
                $overrides['amount_cents'] ?? 150_000,
            );
        }

        return $employee->refresh();
    };

    $this->sheetDay = fn (string $date, array $attributes = []) => LedgerEntry::create([
        'truck_id' => $this->truck->id,
        'date' => $date,
        'trip_income_cents' => 5_000_00,
        ...$attributes,
    ]);

    $this->haul = function (string $date, string $driverId, ?string $helperId = null, string $status = 'delivered') {
        static $n = 0;

        $trip = Trip::create([
            'reference' => 'TRIP-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'origin' => 'Davao City',
            'destination' => 'Tagum City',
            'cargo' => 'Assorted',
            'weight_kg' => 2000,
            'driver_id' => $driverId,
            'status' => $status,
            'scheduled_at' => $date.' 07:00:00',
        ]);

        $trip->setHelpers($helperId === null ? [] : [$helperId]);

        return $trip;
    };

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);
});

describe('setting somebody to per trip', function (): void {
    it('puts them on the run, with no second switch to find', function (): void {
        $employee = ($this->hireDriver)();
        ($this->haul)('2026-09-03', $employee->driver_id);

        $run = ($this->open)()->assertCreated()->json('data');

        expect($run['staff_count'])->toBe(1)
            ->and($run['lines'][0]['basic_cents'])->toBe(150_000);
    });

    it('still keeps somebody with no contract off the run', function (): void {
        Employee::create([
            'employee_no' => 'EMP-9100', 'first_name' => 'Cash', 'last_name' => 'Settled',
            'position' => 'Driver', 'contact' => '0917 555 0100', 'hired_on' => '2026-01-05',
            'status' => 'active',
        ]);

        expect(($this->open)()->assertCreated()->json('data.staff_count'))->toBe(0);
    });
});

describe('paying per trip', function (): void {
    beforeEach(function (): void {
        $this->employee = ($this->hireDriver)();
    });

    it('pays the trip rate for every haul delivered in the period', function (): void {
        ($this->haul)('2026-09-03', $this->employee->driver_id);
        ($this->haul)('2026-09-09', $this->employee->driver_id);
        ($this->haul)('2026-09-20', $this->employee->driver_id);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(300_000)
            ->and($line['pay_basis'])->toBe('per_trip')
            ->and($line['trips'])->toBe(2)
            ->and($line['pay_basis_label'])->toBe('Per trip');
    });

    it('counts hauls rather than days', function (): void {
        foreach (range(1, 3) as $ignored) {
            ($this->haul)('2026-09-03', $this->employee->driver_id);
        }

        ($this->sheetDay)('2026-09-03', [
            'driver_id' => $this->employee->driver_id, 'driver_salary_cents' => 120_000,
        ]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(450_000)
            ->and($line['trips'])->toBe(3)
            ->and($line['sheet_days'])->toBe(1);
    });

    it('does not halve a period already worked', function (): void {
        ($this->haul)('2026-09-03', $this->employee->driver_id);

        expect(($this->open)()->assertCreated()->json('data.lines.0.basic_cents'))->toBe(150_000);
    });

    it('pays them for riding as helper too', function (): void {
        $other = ($this->hireDriver)([
            'name' => 'Jun Santos', 'licence_no' => 'N02-33-556677',
            'employee_no' => 'EMP-9002', 'first_name' => 'Jun', 'last_name' => 'Santos',
        ]);

        ($this->haul)('2026-09-03', $this->employee->driver_id);
        ($this->haul)('2026-09-05', $other->driver_id, $this->employee->driver_id);

        $lines = collect(($this->open)()->assertCreated()->json('data.lines'))->keyBy('employee_no');

        expect($lines['EMP-9001']['basic_cents'])->toBe(300_000)
            ->and($lines['EMP-9001']['trips'])->toBe(2);
    });
});

describe('paying per trip, and what does not count', function (): void {
    beforeEach(function (): void {
        $this->employee = ($this->hireDriver)();
    });

    it('counts only what was actually delivered', function (): void {
        ($this->haul)('2026-09-03', $this->employee->driver_id);
        ($this->haul)('2026-09-04', $this->employee->driver_id, null, 'in_transit');
        ($this->haul)('2026-09-05', $this->employee->driver_id, null, 'cancelled');

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(150_000)
            ->and($line['trips'])->toBe(1);
    });

    it('does not pay one driver another driver’s haul', function (): void {
        $other = ($this->hireDriver)([
            'name' => 'Jun Santos', 'licence_no' => 'N02-33-556677',
            'employee_no' => 'EMP-9002', 'first_name' => 'Jun', 'last_name' => 'Santos',
            'amount_cents' => 100_000,
        ]);

        ($this->haul)('2026-09-03', $this->employee->driver_id);
        ($this->haul)('2026-09-04', $other->driver_id);

        $lines = collect(($this->open)()->assertCreated()->json('data.lines'))
            ->keyBy('employee_no');

        expect($lines['EMP-9001']['basic_cents'])->toBe(150_000)
            ->and($lines['EMP-9002']['basic_cents'])->toBe(100_000);
    });

    it('still deducts the statutory contributions, from what they actually earned', function (): void {
        foreach (['2026-09-02', '2026-09-04', '2026-09-08', '2026-09-10', '2026-09-12', '2026-09-14'] as $date) {
            ($this->haul)($date, $this->employee->driver_id);
        }

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['sss_cents'])->toBeGreaterThan(0)
            ->and($line['philhealth_cents'])->toBeGreaterThan(0)
            ->and($line['pagibig_cents'])->toBeGreaterThan(0)
            ->and($line['net_cents'])->toBe($line['gross_cents'] - $line['deductions_cents']);
    });

    it('leaves somebody with no drivers record off the run', function (): void {
        $clerk = Employee::create([
            'employee_no' => 'EMP-9003', 'first_name' => 'Ana', 'last_name' => 'Cruz',
            'position' => 'Office Staff', 'contact' => '0917 555 0999', 'hired_on' => '2026-01-05',
            'status' => 'active',
        ]);

        payContract($clerk, 'per_trip', 150_000);

        $names = collect(($this->open)()->assertCreated()->json('data.lines'))->pluck('name');

        expect($names)->not->toContain('Ana Cruz');
    });

    it('says which zero it is when a payslip comes to nothing', function (): void {
        ($this->haul)('2026-09-03', $this->employee->driver_id);

        $idle = ($this->hireDriver)([
            'name' => 'Rosa Lim', 'licence_no' => 'N03-44-667788',
            'employee_no' => 'EMP-9005', 'first_name' => 'Rosa', 'last_name' => 'Lim',
        ]);

        $line = collect(($this->open)()->assertCreated()->json('data.lines'))
            ->firstWhere('employee_no', $idle->employee_no);

        expect($line['basic_cents'])->toBe(0)
            ->and($line['zero_explanation'])->toContain('No hauls were delivered');
    });
});

describe('paying a daily rate', function (): void {
    it('multiplies the rate by the days they were out', function (): void {
        $employee = ($this->hireDriver)([
            'pay_basis' => 'daily',
            'amount_cents' => 80_000,
        ]);

        foreach (['2026-09-02', '2026-09-04', '2026-09-08'] as $date) {
            ($this->sheetDay)($date, [
                'driver_id' => $employee->driver_id,
                'driver_salary_cents' => 999_999,
            ]);
        }

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(240_000)
            ->and($line['days_worked'])->toBe(3)
            ->and($line['pay_basis'])->toBe('daily')
            ->and($line['pay_basis_label'])->toBe('Daily rate');
    });

    it('counts a day once, however many trucks they were on', function (): void {
        $employee = ($this->hireDriver)(['pay_basis' => 'daily', 'amount_cents' => 80_000]);
        $second = Truck::create(['label' => 'Truck 2', 'plate' => 'NCR 5513', 'position' => 2]);

        ($this->sheetDay)('2026-09-03', ['driver_id' => $employee->driver_id]);
        LedgerEntry::create([
            'truck_id' => $second->id,
            'date' => '2026-09-03',
            'driver_id' => $employee->driver_id,
        ]);

        expect(($this->open)()->assertCreated()->json('data.lines.0.days_worked'))->toBe(1);
    });

    it('counts an unattributed row toward nobody', function (): void {
        $employee = ($this->hireDriver)(['pay_basis' => 'daily', 'amount_cents' => 80_000]);

        ($this->sheetDay)('2026-09-03', ['driver_id' => $employee->driver_id]);
        ($this->sheetDay)('2026-09-04', ['driver_salary_cents' => 999_000]);

        expect(($this->open)()->assertCreated()->json('data.lines.0.basic_cents'))->toBe(80_000);
    });

    it('pays nothing for a period they did not work', function (): void {
        ($this->hireDriver)(['pay_basis' => 'daily', 'amount_cents' => 80_000]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(0)
            ->and($line['days_worked'])->toBe(0);
    });
});

describe('alongside the salaried', function (): void {
    it('puts both kinds on one run, each worked out its own way', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Elena', 'last_name' => 'Bautista', 'position' => 'Office Staff',
            'contact' => '0917 555 0199', 'hired_on' => '2026-01-05',
            'pay_basis' => 'monthly', 'amount_cents' => 3_000_000,
        ])->assertCreated();

        $driver = ($this->hireDriver)();
        ($this->haul)('2026-09-03', $driver->driver_id);

        $lines = collect(($this->open)()->assertCreated()->json('data.lines'))->keyBy('name');

        expect($lines)->toHaveCount(2)
            ->and($lines['Elena Bautista']['basic_cents'])->toBe(1_500_000)
            ->and($lines['Elena Bautista']['pay_basis'])->toBe('monthly')
            ->and($lines['Elena Bautista']['trips'])->toBe(0)
            ->and($lines['Marco Villanueva']['basic_cents'])->toBe(150_000)
            ->and($lines['Marco Villanueva']['pay_basis'])->toBe('per_trip');
    });

    it('keeps a monthly employee with no figure off the run', function (): void {
        $employee = Employee::create([
            'employee_no' => 'EMP-9004', 'first_name' => 'Half', 'last_name' => 'Setup',
            'position' => 'Office Staff', 'contact' => '0917 555 0111', 'hired_on' => '2026-01-05',
            'status' => 'active',
        ]);

        payContract($employee, 'monthly', 0);

        expect(($this->open)()->assertCreated()->json('data.staff_count'))->toBe(0);
    });
});

describe('the truck sheet naming its crew', function (): void {
    it('records who the driver and helper were', function (): void {
        $employee = ($this->hireDriver)();

        $row = $this->actingAs($this->admin)->postJson('/api/v1/ledger', [
            'truck_id' => $this->truck->id,
            'date' => '2026-09-03',
            'driver_id' => $employee->driver_id,
            'driver_salary_cents' => 120_000,
        ])->assertCreated()->json('data');

        expect($row['driver_id'])->toBe($employee->driver_id)
            ->and($row['driver_name'])->toBe('Marco Villanueva')
            ->and($row['helpers'])->toBe([]);
    });

    it('refuses the same person as driver and helper', function (): void {
        $employee = ($this->hireDriver)();

        $this->actingAs($this->admin)->postJson('/api/v1/ledger', [
            'truck_id' => $this->truck->id,
            'date' => '2026-09-03',
            'driver_id' => $employee->driver_id,
            'helpers' => [['driver_id' => $employee->driver_id, 'salary_cents' => 50_000]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['helpers.0.driver_id' => 'The driver and a helper cannot be the same person.']);
    });
});

describe('what the employee record says', function (): void {
    it('reports the basis and whether the figure is multiplied by work', function (): void {
        $employee = ($this->hireDriver)();

        $row = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee->id}")->assertOk()->json('data');

        expect($row['pay_basis'])->toBe('per_trip')
            ->and($row['pay_basis_label'])->toBe('Per trip')
            ->and($row['amount_cents'])->toBe(150_000)
            ->and($row['pay_summary'])->toBe('₱1,500 a trip')
            ->and($row['paid_per_unit_worked'])->toBeTrue();
    });

    it('defaults an ordinary hire to monthly', function (): void {
        $row = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Elena', 'last_name' => 'Bautista', 'position' => 'Office Staff',
            'contact' => '0917 555 0199', 'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

        expect($row['pay_basis'])->toBe('monthly')
            ->and($row['paid_per_unit_worked'])->toBeFalse();
    });

    it('lets an office switch somebody onto a daily rate', function (): void {
        $employee = ($this->hireDriver)();

        $row = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee->id}", [
                'pay_basis' => 'daily',
                'amount_cents' => 75_000,
            ])->assertOk()->json('data');

        expect($row['pay_basis'])->toBe('daily')
            ->and($row['amount_cents'])->toBe(75_000);

        expect($employee->contracts()->count())->toBe(2);
    });

    it('carries the figure over when only the basis moves', function (): void {
        $employee = ($this->hireDriver)();

        $row = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee->id}", ['pay_basis' => 'daily'])
            ->assertOk()->json('data');

        expect($row['pay_basis'])->toBe('daily')
            ->and($row['amount_cents'])->toBe(150_000);
    });
});
