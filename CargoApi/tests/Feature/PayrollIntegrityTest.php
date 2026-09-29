<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLineTrip;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Payroll\Services\StatutoryDeductions;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Payroll's integrity: the right tax table, one run per period, a tab taken
 * once, every trip paid exactly once, no payslip below nothing, and wages in
 * the right half of the income statement.
 *
 * Each block is a bug that shipped. The worked figures are asserted to the
 * centavo because each one is a sum somebody can redo on paper from the BIR's
 * own table.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->hire = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->hireDriver = function (): Employee {
        $driver = Driver::create([
            'name' => 'Marco Villanueva',
            'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-08-31',
            'status' => 'available',
        ]);

        $employee = Employee::create([
            'employee_no' => 'EMP-9001',
            'first_name' => 'Marco',
            'last_name' => 'Villanueva',
            'position' => 'Driver',
            'contact' => '0917 555 0123',
            'hired_on' => '2026-01-05',
            'status' => 'active',
            'driver_id' => $driver->id,
        ]);

        // ₱1,500 a haul.
        payContract($employee, 'per_trip', 150_000);

        return $employee->refresh();
    };

    $this->haul = function (string $date, string $driverId): Trip {
        static $n = 0;

        return Trip::create([
            'reference' => 'TRIP-INT-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'origin' => 'Davao City',
            'destination' => 'Tagum City',
            'cargo' => 'Assorted',
            'weight_kg' => 2000,
            'driver_id' => $driverId,
            'status' => 'delivered',
            'scheduled_at' => $date.' 07:00:00',
        ]);
    };

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);

    $this->approve = fn (string $id) => $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$id}/approve");
    $this->pay = fn (string $id) => $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$id}/pay");
    $this->adjust = fn (array $run, array $changes) => $this->actingAs($this->admin)
        ->patchJson("/api/v1/payroll/{$run['id']}/lines/{$run['lines'][0]['id']}", $changes);
});

describe('the withholding tables', function (): void {
    it('withholds ₱10,937.45 on ₱60,000 of semi-monthly taxable pay', function (): void {
        // Over ₱33,333: ₱4,270.70 + 25% of the ₱26,667 above it. The old table
        // put this in a 30% bracket that is not on the 2023 schedule at all.
        $tax = app(StatutoryDeductions::class)->for(
            monthlyBasicCents: 12_000_000,
            periodGrossCents: 6_000_000,
            index: 0,
            count: 2,
            enrolled: ['sss' => false, 'philhealth' => false, 'pagibig' => false],
        )['withholding_tax'];

        expect($tax)->toBe(1_093_745);
    });

    it('taxes a single-cutoff ₱50,000 month on the monthly table', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        ($this->hire)(['amount_cents' => 5_000_000]);

        $line = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        // ₱1,750 SSS (5% of the ₱35,000 ceiling), ₱1,250 PhilHealth, ₱200
        // Pag-IBIG — taxable ₱46,800. Over ₱33,333 on the *monthly* table:
        // ₱1,875 + 20% of ₱13,467 = ₱4,568.40. The semi-monthly table this
        // used to read charged about ₱7,638.
        expect($line['sss_cents'])->toBe(175_000)
            ->and($line['philhealth_cents'])->toBe(125_000)
            ->and($line['pagibig_cents'])->toBe(20_000)
            ->and($line['withholding_tax_cents'])->toBe(456_840);
    });

    it('comes to the same month on two semi-monthly payslips, within a centavo or so', function (): void {
        ($this->hire)(['amount_cents' => 5_000_000]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        // ₱25,000 less ₱1,600 of contributions is ₱23,400: ₱937.50 + 20% of
        // ₱6,733 = ₱2,284.10 — twice that is within ₱0.20 of the monthly
        // figure above, which is how the two tables are meant to agree.
        expect($line['withholding_tax_cents'])->toBe(228_410);
    });

    it('annualises a three-cutoff run through the monthly table', function (): void {
        $tax = app(StatutoryDeductions::class)->for(
            monthlyBasicCents: 3_000_000,
            periodGrossCents: 1_000_000,
            index: 0,
            count: 3,
            enrolled: ['sss' => false, 'philhealth' => false, 'pagibig' => false],
        )['withholding_tax'];

        // ₱30,000 a month on the monthly table is 15% of ₱9,167 = ₱1,375.05,
        // a third of it per run.
        expect($tax)->toBe(45_835);
    });

    it('takes the 2025 SSS rate and ceiling', function (): void {
        $deductions = app(StatutoryDeductions::class);

        expect($deductions->for(10_000_000, 5_000_000, 0, 1)['sss'])->toBe(175_000)
            // The ₱5,000 floor on a small basic…
            ->and($deductions->for(300_000, 300_000, 0, 1)['sss'])->toBe(25_000)
            // …and nothing on nothing.
            ->and($deductions->for(0, 0, 0, 1)['sss'])->toBe(0);
    });
});

describe('who is exempt', function (): void {
    it('counts a recurring taxable allowance toward the exemption', function (): void {
        $employee = ($this->hire)(['amount_cents' => 1_800_000]);

        $allowance = $this->actingAs($this->admin)->postJson('/api/v1/payroll/components', [
            'name' => 'Transport allowance',
            'kind' => 'earning',
            'basis' => 'fixed',
            'amount_cents' => 600_000,
            'schedule' => 'monthly_split',
            'taxable' => true,
        ])->assertCreated()->json('data');

        $this->actingAs($this->admin)->postJson('/api/v1/payroll/assignments', [
            'employee_id' => $employee['id'],
            'pay_component_id' => $allowance['id'],
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        // ₱18,000 + ₱6,000 is ₱24,000 a month — over the ₱20,833 exemption,
        // so taxed: ₱9,000 + ₱3,000 less ₱775 of contributions is ₱11,225,
        // 15% of the ₱808 above ₱10,417.
        expect($line['withholding_tax_cents'])->toBe(12_120);
    });

    it('still exempts the basic alone', function (): void {
        $deductions = app(StatutoryDeductions::class);

        expect($deductions->isExempt(1_800_000))->toBeTrue()
            ->and($deductions->isExempt(1_800_000, 600_000))->toBeFalse();
    });
});

describe('one run per period', function (): void {
    it('refuses a second run for the same period', function (): void {
        ($this->hire)();

        ($this->open)()->assertCreated();

        ($this->open)()->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'already covers'));

        expect(PayRun::count())->toBe(1);
    });

    it('refuses a run overlapping one on a different calendar', function (): void {
        ($this->hire)();
        ($this->open)()->assertCreated();

        config(['cargo.payroll.runs_per_month' => 1]);

        ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])->assertStatus(422);
    });

    it('opens the period again once the draft is deleted', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)->deleteJson("/api/v1/payroll/{$run['id']}")->assertNoContent();

        ($this->open)()->assertCreated();
    });

    it('lets only one of two duplicate drafts be approved', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        // A duplicate from before the rule, or from a race.
        $original = PayRun::with('lines')->findOrFail($run['id']);
        $twin = $original->replicate(['reference']);
        $twin->save();

        foreach ($original->lines as $line) {
            $copy = $line->replicate();
            $copy->pay_run_id = $twin->getKey();
            $copy->save();
        }

        ($this->approve)($run['id'])->assertOk();
        ($this->approve)($twin->getKey())->assertStatus(422);
    });
});

describe('the store tab', function (): void {
    beforeEach(function (): void {
        $this->employee = ($this->hire)();

        $this->actingAs($this->admin)->postJson("/api/v1/employees/{$this->employee['id']}/store-credits", [
            'kind' => 'charge',
            'amount_cents' => 50_000,
            'description' => '3 kg rice',
            'charged_on' => '2026-09-05',
        ])->assertCreated();
    });

    it('is not taken by two open drafts at once', function (): void {
        $first = ($this->open)()->assertCreated()->json('data.lines.0');
        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($first['store_deduction_cents'])->toBe(50_000)
            ->and($second['store_deduction_cents'])->toBe(0);
    });

    it('is re-capped at approval, and never driven below zero', function (): void {
        $run = ($this->open)()->assertCreated()->json('data');

        // Paid down in cash after the period closed, before approval.
        $this->actingAs($this->admin)->postJson("/api/v1/employees/{$this->employee['id']}/store-credits", [
            'kind' => 'payment',
            'amount_cents' => 30_000,
            'charged_on' => '2026-09-16',
        ])->assertCreated();

        $approved = ($this->approve)($run['id'])->assertOk()->json('data.lines.0');

        expect($approved['store_deduction_cents'])->toBe(20_000)
            ->and($approved['net_cents'])->toBe($approved['gross_cents'] - $approved['deductions_cents']);

        $balance = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$this->employee['id']}/store-credits")
            ->json('meta.balance_cents');

        expect($balance)->toBe(0);
    });

    it('does not take a later run’s repayment again for an earlier period', function (): void {
        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data');
        ($this->approve)($second['id'])->assertOk();

        // The second half's repayment is dated its own pay day, after this
        // period ends — and must still count against it.
        $first = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($first['store_deduction_cents'])->toBe(0)
            ->and((int) StoreCredit::where('kind', 'payment')->sum('amount_cents'))->toBe(50_000);
    });
});

describe('a payslip never pays less than nothing', function (): void {
    it('refuses an adjustment that leaves the net negative, and saves nothing', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        ($this->adjust)($run, ['other_deductions_cents' => 2_000_000])->assertStatus(422);

        $line = PayRun::with('lines')->findOrFail($run['id'])->lines->first();

        expect($line->other_deductions_cents)->toBe(0)
            ->and($line->net_cents)->toBe(1_327_130);
    });

    it('lets the store deduction give way first', function (): void {
        $employee = ($this->hire)();
        $this->actingAs($this->admin)->postJson("/api/v1/employees/{$employee['id']}/store-credits", [
            'kind' => 'charge', 'amount_cents' => 50_000, 'charged_on' => '2026-09-05',
        ])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');

        // ₱13,271.30 before the tab. Leave ₱271.30 of room and the tab takes
        // that, not ₱500; the rest waits for the next payslip.
        $line = ($this->adjust)($run, ['other_deductions_cents' => 1_300_000])->assertOk()->json('data.lines.0');

        expect($line['store_deduction_cents'])->toBe(27_130)
            ->and($line['net_cents'])->toBe(0);

        // And grows back when the room returns.
        $line = ($this->adjust)($run, ['other_deductions_cents' => 0])->assertOk()->json('data.lines.0');

        expect($line['store_deduction_cents'])->toBe(50_000);
    });

    it('refuses a hand-added deduction the payslip cannot bear', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/lines/{$run['lines'][0]['id']}/deductions", [
                'name' => 'Breakage', 'amount_cents' => 2_000_000,
            ])->assertStatus(422);

        expect(PayRun::with('lines.components')->findOrFail($run['id'])->lines->first()->components)->toBeEmpty();
    });
});

describe('every trip paid exactly once', function (): void {
    beforeEach(function (): void {
        $this->driver = ($this->hireDriver)();
    });

    it('pays a back-dated trip on the next run, and nothing twice', function (): void {
        ($this->haul)('2026-09-05', $this->driver->driver_id);

        $first = ($this->open)()->assertCreated()->json('data');
        expect($first['lines'][0]['trips'])->toBe(1);
        ($this->approve)($first['id'])->assertOk();

        // Delivered on the 10th, entered after the 1st–15th was approved.
        ($this->haul)('2026-09-10', $this->driver->driver_id);
        ($this->haul)('2026-09-20', $this->driver->driver_id);

        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data');

        // The 20th and the late 10th; the 5th was paid already.
        expect($second['lines'][0]['trips'])->toBe(2)
            ->and($second['lines'][0]['basic_cents'])->toBe(300_000);

        ($this->approve)($second['id'])->assertOk();

        $third = ($this->open)(['period_start' => '2026-10-01', 'period_end' => '2026-10-15'])
            ->assertCreated()->json('data');

        expect($third['lines'][0]['trips'])->toBe(0);

        // Three trips, three payments, across three runs.
        expect(PayRunLineTrip::query()->settled()->count())->toBe(3);
    });

    it('refuses to approve a second draft carrying a trip the first has paid', function (): void {
        ($this->haul)('2026-09-05', $this->driver->driver_id);
        $first = ($this->open)()->assertCreated()->json('data');
        ($this->approve)($first['id'])->assertOk();

        ($this->haul)('2026-09-10', $this->driver->driver_id);

        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data');
        $third = ($this->open)(['period_start' => '2026-10-01', 'period_end' => '2026-10-15'])
            ->assertCreated()->json('data');

        // Both drafts reached back for the late trip.
        expect($second['lines'][0]['trips'])->toBe(1)
            ->and($third['lines'][0]['trips'])->toBe(1);

        ($this->approve)($second['id'])->assertOk();
        ($this->approve)($third['id'])->assertStatus(422);

        // Worked out again, it drops the trip and can go.
        $rebuilt = $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$third['id']}/rebuild")
            ->assertOk()->json('data');

        expect($rebuilt['lines'][0]['trips'])->toBe(0);
        ($this->approve)($third['id'])->assertOk();
    });
});

describe('where the wages post', function (): void {
    it('posts crew wages to cost of services and the office to 5200, balanced', function (): void {
        $driver = ($this->hireDriver)();
        ($this->hire)();
        ($this->haul)('2026-09-05', $driver->driver_id);

        $run = ($this->open)()->assertCreated()->json('data');
        ($this->approve)($run['id'])->assertOk();
        $paid = ($this->pay)($run['id'])->assertOk()->json('data');

        $lines = collect(
            $this->actingAs($this->admin)
                ->getJson("/api/v1/accounting/journal/{$paid['journal_entry_id']}")
                ->json('data.lines')
        );

        $wages = $lines->reject(fn (array $line): bool => str_contains((string) $line['memo'], 'employer share'));

        // The wages, and each side's employer share beside them: the driver's
        // ₱3,000 month (one ₱1,500 haul, times two) gives ₱410 a cutoff, the
        // office's ₱30,000 gives ₱1,990.
        expect($wages->where('account_code', '5020')->sum('debit_cents'))->toBe(150_000)
            ->and($wages->where('account_code', '5200')->sum('debit_cents'))->toBe(1_500_000)
            ->and($lines->where('account_code', '5020')->sum('debit_cents'))->toBe(150_000 + 41_000)
            ->and($lines->where('account_code', '5200')->sum('debit_cents'))->toBe(1_500_000 + 199_000)
            ->and($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'));
    });
});
