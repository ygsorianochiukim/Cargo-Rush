<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Services\PayrollCostService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Services\StatutoryDeductions;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The employer's share of SSS, EC, PhilHealth and Pag-IBIG.
 *
 * The worked case throughout is ₱30,000 a month on two cutoffs. A month of it
 * costs the firm, on top of the salary:
 *
 *     SSS         10% of the ₱30,000 credit     ₱3,000.00
 *     EC          credit ≥ ₱15,000                  ₱30.00
 *     PhilHealth  2.5% of ₱30,000                  ₱750.00
 *     Pag-IBIG    2% of ₱30,000, capped at ₱200    ₱200.00
 *                                                 ─────────
 *                                                 ₱3,980.00  → ₱1,990 a cutoff
 *
 * none of which comes off the payslip.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    // ₱30,000 a month, office.
    $this->hire = fn () => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

    $this->open = fn (string $start = '2026-09-01', string $end = '2026-09-15') => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => $start,
            'period_end' => $end,
            'pay_date' => $end,
        ])->assertCreated()->json('data');

    $this->payRun = function (array $run): array {
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        return $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/pay")->assertOk()->json('data');
    };

    $this->period = fn (string $from, string $to) => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/profitability?from={$from}&to={$to}")
        ->assertOk()
        ->json('data.totals');

    $this->lines = fn (string $from, string $to) => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/expense-lines?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');
});

describe('the arithmetic', function (): void {
    it('works out ₱1,990 a cutoff on ₱30,000 a month', function (): void {
        $share = app(StatutoryDeductions::class)->employerFor(3_000_000, index: 0, count: 2);

        expect($share)->toBe(['sss' => 150_000, 'ec' => 1_500, 'philhealth' => 37_500, 'pagibig' => 10_000]);
    });

    it('charges ₱10 EC below a ₱15,000 credit, and caps Pag-IBIG at ₱200', function (): void {
        // ₱12,000, paid monthly: SSS 10% of ₱12,000, EC ₱10, PhilHealth 2.5%
        // of ₱12,000, Pag-IBIG 2% would be ₱240 — held at ₱200.
        $share = app(StatutoryDeductions::class)->employerFor(1_200_000, index: 0, count: 1);

        expect($share)->toBe(['sss' => 120_000, 'ec' => 1_000, 'philhealth' => 30_000, 'pagibig' => 20_000]);
    });

    it('reads the floors on a low basic, like the employee side', function (): void {
        // ₱4,000: SSS on the ₱5,000 floor credit, PhilHealth on its ₱10,000
        // floor, Pag-IBIG on what is actually earned.
        $share = app(StatutoryDeductions::class)->employerFor(400_000, index: 0, count: 1);

        expect($share)->toBe(['sss' => 50_000, 'ec' => 1_000, 'philhealth' => 25_000, 'pagibig' => 8_000]);
    });

    it('costs nothing on nothing, and nothing where the person is not enrolled', function (): void {
        $deductions = app(StatutoryDeductions::class);

        expect($deductions->employerFor(0, 0, 2))
            ->toBe(['sss' => 0, 'ec' => 0, 'philhealth' => 0, 'pagibig' => 0])
            // EC rides on SSS membership.
            ->and($deductions->employerFor(3_000_000, 0, 1, enrolled: ['sss' => false]))
            ->toBe(['sss' => 0, 'ec' => 0, 'philhealth' => 75_000, 'pagibig' => 20_000]);
    });

    it('splits across the cutoffs exactly as the employee side does', function (): void {
        $deductions = app(StatutoryDeductions::class);
        $month = $deductions->employerFor(3_333_333, 0, 1);

        $first = $deductions->employerFor(3_333_333, 0, 2);
        $second = $deductions->employerFor(3_333_333, 1, 2);

        foreach (['sss', 'ec', 'philhealth', 'pagibig'] as $agency) {
            expect($first[$agency] + $second[$agency])->toBe($month[$agency]);
        }

        // A firm taking the month on the first cutoff takes its own share there too.
        expect($deductions->employerFor(3_000_000, 1, 2, DeductionSchedule::FirstCutoff))
            ->toBe(['sss' => 0, 'ec' => 0, 'philhealth' => 0, 'pagibig' => 0])
            ->and($deductions->employerFor(3_000_000, 0, 2, DeductionSchedule::FirstCutoff)['sss'])->toBe(300_000);
    });
});

describe('on the run', function (): void {
    it('stores the share on the line and the run, and leaves the payslip alone', function (): void {
        ($this->hire)();

        $run = ($this->open)();
        $line = $run['lines'][0];

        expect($line['employer_sss_cents'])->toBe(150_000)
            ->and($line['employer_ec_cents'])->toBe(1_500)
            ->and($line['employer_philhealth_cents'])->toBe(37_500)
            ->and($line['employer_pagibig_cents'])->toBe(10_000)
            ->and($line['employer_contributions_cents'])->toBe(199_000)
            ->and($run['employer_contributions'])->toBe([
                'sss' => 150_000, 'ec' => 1_500, 'philhealth' => 37_500, 'pagibig' => 10_000, 'total' => 199_000,
            ])
            // Not deducted: ₱15,000 gross, and the net is the gross less only
            // what the employee's own side and the tax took.
            ->and($line['gross_cents'])->toBe(1_500_000)
            ->and($line['net_cents'])->toBe(
                1_500_000 - $line['sss_cents'] - $line['philhealth_cents'] - $line['pagibig_cents'] - $line['withholding_tax_cents'],
            );
    });

    it('posts the share as an expense against the agencies’ payable, balanced', function (): void {
        ($this->hire)();

        $run = ($this->open)();
        $paid = ($this->payRun)($run);

        $lines = collect(
            $this->actingAs($this->admin)
                ->getJson("/api/v1/accounting/journal/{$paid['journal_entry_id']}")
                ->json('data.lines')
        );

        $withheld = $paid['statutory']['sss'] + $paid['statutory']['philhealth'] + $paid['statutory']['pagibig'];

        // Office staff: wages and the employer share both on 5200.
        expect($lines->where('account_code', '5200')->sum('debit_cents'))->toBe(1_500_000 + 199_000)
            ->and($lines->where('account_code', '2200')->sum('credit_cents'))->toBe($withheld + 199_000)
            ->and($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'))
            ->and($lines->firstWhere('memo', 'SSS, PhilHealth and Pag-IBIG — employer share')['credit_cents'])->toBe(199_000);
    });

    it('posts a driver’s share to cost of services', function (): void {
        $driver = Driver::create([
            'name' => 'Marco Villanueva', 'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-08-31', 'status' => 'available',
        ]);
        $employee = Employee::create([
            'employee_no' => 'EMP-9101', 'first_name' => 'Marco', 'last_name' => 'Villanueva',
            'position' => 'Driver', 'contact' => '0917 555 0123', 'hired_on' => '2026-01-05',
            'status' => 'active', 'driver_id' => $driver->id,
        ]);
        payContract($employee, 'monthly', 3_000_000);

        $paid = ($this->payRun)(($this->open)());

        $lines = collect(
            $this->actingAs($this->admin)
                ->getJson("/api/v1/accounting/journal/{$paid['journal_entry_id']}")
                ->json('data.lines')
        );

        expect($lines->where('account_code', '5020')->sum('debit_cents'))->toBe(1_500_000 + 199_000)
            ->and($lines->where('account_code', '5200')->sum('debit_cents'))->toBe(0)
            ->and($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'));
    });
});

describe('in Finance', function (): void {
    it('counts the share in the payroll cost, and lists it in a drill-down that still adds up', function (): void {
        ($this->hire)();
        ($this->payRun)(($this->open)());

        $totals = ($this->period)('2026-09-01', '2026-09-30');
        $lines = ($this->lines)('2026-09-01', '2026-09-30');
        $payroll = collect($lines['lines'])->where('source', 'payroll');

        expect($totals['payroll_cents'])->toBe(1_500_000 + 199_000)
            ->and($lines['total_cents'])->toBe($totals['total_expenses_cents'])
            ->and($payroll->firstWhere('kind', 'Payroll')['amount_cents'])->toBe(1_500_000)
            ->and($payroll->firstWhere('kind', 'Employer contributions')['amount_cents'])->toBe(199_000);
    });

    it('counts the share even where the sheet already carries the whole wage', function (): void {
        $driver = Driver::create([
            'name' => 'Marco Reyes', 'licence_no' => 'N01-23-111111',
            'licence_expiry' => '2029-08-31', 'status' => 'available',
        ]);
        $employee = Employee::create([
            'employee_no' => 'EMP-9102', 'first_name' => 'Marco', 'last_name' => 'Reyes',
            'position' => 'Driver', 'contact' => '0917 555 0124', 'hired_on' => '2026-01-05',
            'status' => 'active', 'driver_id' => $driver->id,
        ]);
        // ₱10,000 a month: ₱5,000 a cutoff, and the firm's share a cutoff is
        // SSS ₱500 + EC ₱5 + PhilHealth ₱125 + Pag-IBIG ₱100 = ₱730.
        payContract($employee, 'monthly', 1_000_000);

        $vehicle = Vehicle::create([
            'plate' => 'EMP-4411', 'model' => 'Isuzu Forward', 'registration_no' => 'REG-4411',
            'capacity_kg' => 15_000, 'status' => 'available',
        ]);
        $truck = Truck::create(['label' => 'Truck 44', 'plate' => $vehicle->plate, 'vehicle_id' => $vehicle->id, 'position' => 44]);

        // The sheet paid him ₱6,000 in the period — more than the payslip.
        LedgerEntry::create([
            'truck_id' => $truck->id, 'date' => '2026-09-10', 'driver_id' => $driver->id,
            'trip_income_cents' => 0, 'driver_salary_cents' => 600_000,
        ]);

        $run = ($this->open)();

        expect($run['employer_contributions']['total'])->toBe(73_000);

        ($this->payRun)($run);

        // The wage is on the sheet already; the share never is.
        expect(($this->period)('2026-09-01', '2026-09-30')['payroll_cents'])->toBe(73_000);
    });

    it('owes the agencies the share until remitted, already costed', function (): void {
        ($this->hire)();
        $paid = ($this->payRun)(($this->open)());

        $s = $paid['statutory'];
        $withheld = $s['sss'] + $s['philhealth'] + $s['pagibig'] + $s['withholding_tax'];

        $page = $this->actingAs($this->admin)->getJson('/api/v1/finance/payables')->assertOk()->json('data');
        $group = collect($page['groups'])->firstWhere('key', 'payroll');

        $payroll = app(PayrollCostService::class);

        expect($group['total_cents'])->toBe($withheld + 199_000)
            ->and($payroll->owedCentsAsOf(Carbon::parse('2026-10-31')))->toBe($withheld + 199_000)
            // In the expenses already, so actual income does not take it twice.
            ->and($payroll->costedOwedAsOf(Carbon::parse('2026-10-31')))->toBe($withheld + 199_000)
            // Presumed remitted by the end of the month after the period.
            ->and($payroll->owedCentsAsOf(Carbon::parse('2026-11-01')))->toBe(0);
    });
});
