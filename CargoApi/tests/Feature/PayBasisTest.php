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

/**
 * Paying the people who are not on a salary.
 *
 * Until this existed payroll could express exactly one arrangement — a monthly
 * figure — and everybody else was silently left off every run. A driver could
 * work here for a year and have no record that SSS had ever been deducted from
 * anything, because their money went out through the daily truck sheet and
 * never reached a payslip, a contribution or the books.
 *
 * What these tests are defending:
 *
 *   **The basis is the whole instruction.** Set somebody to per trip and they
 *   are on every run. There is no second switch that can veto it — there used
 *   to be, and a driver missing from a payslip because of a setting on another
 *   screen is the kind of surprise that makes a payroll module feel
 *   untrustworthy. A firm that settles drivers in cash against the sheet leaves
 *   them monthly with no contract instead.
 *
 *   **A rate times the work.** A daily hand is paid their rate for each day the
 *   truck sheet names them on; a per-trip driver their rate for each haul they
 *   delivered. Both rates live on the person's contract.
 *
 *   **Hauls are counted, not days.** A sheet row is one truck's *day* and a
 *   truck can run several hauls in it. Counting rows would pay a driver once
 *   for a day they ran three.
 *
 *   **A period's work is not halved.** A salary is a monthly figure and gets
 *   split across the cutoffs; trip and daily pay is already this period's, and
 *   splitting it would pay half a fortnight's work.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'NCR 4412', 'position' => 1]);

    /** Somebody on the roster with a `drivers` record and a contract behind them. */
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
        ]);

        // ₱1,500 a haul unless a test says otherwise — a round figure, so the
        // sums below are countable rather than arithmetic.
        if (($overrides['amount_cents'] ?? 150_000) > 0) {
            payContract(
                $employee,
                $overrides['pay_basis'] ?? 'per_trip',
                $overrides['amount_cents'] ?? 150_000,
            );
        }

        return $employee->refresh();
    };

    /** A day on the truck sheet, with the crew named. */
    $this->sheetDay = fn (string $date, array $attributes = []) => LedgerEntry::create([
        'truck_id' => $this->truck->id,
        'date' => $date,
        'trip_income_cents' => 5_000_00,
        ...$attributes,
    ]);

    /**
     * A delivered haul, which is what per-trip pay counts.
     *
     * Dated by `scheduled_at`, which is the fallback the whole system uses when
     * there is no proof of delivery on file — the same one the invoice bills on,
     * so a haul lands on the same fortnight in payroll as it does on the bill.
     */
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

/**
 * The basis on the person is the whole instruction.
 *
 * There used to be a company switch that decided whether trip- and day-paid
 * staff reached a payslip at all, and it was removed: setting somebody to *per
 * trip* and then finding them on no run — because of a setting on another
 * screen, in another module — is the kind of surprise that makes a payroll
 * module feel untrustworthy.
 */
describe('setting somebody to per trip', function (): void {
    it('puts them on the run, with no second switch to find', function (): void {
        $employee = ($this->hireDriver)();
        ($this->haul)('2026-09-03', $employee->driver_id);

        $run = ($this->open)()->assertCreated()->json('data');

        expect($run['staff_count'])->toBe(1)
            ->and($run['lines'][0]['basic_cents'])->toBe(150_000);
    });

    it('still keeps somebody with no contract off the run', function (): void {
        // Which is how a firm that hands drivers cash against the truck sheet
        // keeps them off a payslip: never write them a contract, exactly as
        // before any of this existed.
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
        // Outside the period — the second half of the month is its own payslip.
        ($this->haul)('2026-09-20', $this->employee->driver_id);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(300_000)
            ->and($line['pay_basis'])->toBe('per_trip')
            // The workings, so the figure can be checked against the road.
            ->and($line['trips'])->toBe(2)
            ->and($line['pay_basis_label'])->toBe('Per trip');
    });

    it('counts hauls rather than days', function (): void {
        // Three runs on one Tuesday is three hauls and one day. Counting sheet
        // rows would have paid him once — a truck's day is one row, however
        // many times it goes out.
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

        // A salary is a monthly figure and gets split. This is not — it is what
        // they delivered between these two dates, and halving it would pay half
        // a fortnight's work.
        expect(($this->open)()->assertCreated()->json('data.lines.0.basic_cents'))->toBe(150_000);
    });

    it('pays them for riding as helper too', function (): void {
        $other = ($this->hireDriver)([
            'name' => 'Jun Santos', 'licence_no' => 'N02-33-556677',
            'employee_no' => 'EMP-9002', 'first_name' => 'Jun', 'last_name' => 'Santos',
        ]);

        ($this->haul)('2026-09-03', $this->employee->driver_id);
        // Thursday they rode along instead. A relief driver is paid for both.
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
        // Still on the road, and one that never went. Neither is work done, and
        // paying for them would mean clawing it back.
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

        // Each on their own rate, for their own work. Which is the thing a rate
        // card alone cannot do: these two hold the same job.
        expect($lines['EMP-9001']['basic_cents'])->toBe(150_000)
            ->and($lines['EMP-9002']['basic_cents'])->toBe(100_000);
    });

    it('still deducts the statutory contributions, from what they actually earned', function (): void {
        foreach (['2026-09-02', '2026-09-04', '2026-09-08', '2026-09-10', '2026-09-12', '2026-09-14'] as $date) {
            ($this->haul)($date, $this->employee->driver_id);
        }

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        // The whole point of putting them on a payslip: a per-trip driver with
        // no contributions at all was the state this ends. The monthly figure
        // the agencies read is inferred from the period — see
        // `PayrollService::earningsFor()`.
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

        // Every haul names a driver, so there is no work this could find. An
        // empty payslip would be worse than none.
        $names = collect(($this->open)()->assertCreated()->json('data.lines'))->pluck('name');

        expect($names)->not->toContain('Ana Cruz');
    });

    it('says which zero it is when a payslip comes to nothing', function (): void {
        ($this->haul)('2026-09-03', $this->employee->driver_id);

        // No hauls for this one, and a rate on record — so the answer is "they
        // were not out", which is fixed somewhere else entirely from "nobody
        // agreed a rate". Both are zeros and somebody has to be told which.
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
                // Deliberately set: a daily basis reads the *days*, not this.
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

        // Two trucks, one day of work.
        expect(($this->open)()->assertCreated()->json('data.lines.0.days_worked'))->toBe(1);
    });

    it('counts an unattributed row toward nobody', function (): void {
        $employee = ($this->hireDriver)(['pay_basis' => 'daily', 'amount_cents' => 80_000]);

        ($this->sheetDay)('2026-09-03', ['driver_id' => $employee->driver_id]);
        // A day somebody entered by hand and never said whose it was. Skipped
        // rather than guessed at — the safe direction.
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
            // Half a month of ₱30,000.
            ->and($lines['Elena Bautista']['basic_cents'])->toBe(1_500_000)
            ->and($lines['Elena Bautista']['pay_basis'])->toBe('monthly')
            ->and($lines['Elena Bautista']['trips'])->toBe(0)
            // And the period's hauls, unsplit.
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

        // Somebody half set up. A ₱0.00 payslip is worse than none.
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

        // And the trip rate they were on is still there, on the row it was
        // agreed on — switching bases is a new contract, not an overwrite.
        expect($employee->contracts()->count())->toBe(2);
    });

    it('carries the figure over when only the basis moves', function (): void {
        $employee = ($this->hireDriver)();

        // "Same money, paid differently" is a real instruction, and one half of
        // the form is enough to give it.
        $row = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee->id}", ['pay_basis' => 'daily'])
            ->assertOk()->json('data');

        expect($row['pay_basis'])->toBe('daily')
            ->and($row['amount_cents'])->toBe(150_000);
    });
});
