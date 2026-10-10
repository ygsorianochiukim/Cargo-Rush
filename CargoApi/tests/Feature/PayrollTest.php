<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Payroll\Services\StatutoryDeductions;
use App\Domain\Shared\Enums\DeductionSchedule;
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
    $this->accountant = User::where('email', 'accounts@cargorush.ph')->firstOrFail();
    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();

    $this->hire = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
            'sss_enrolled' => true,
            'philhealth_enrolled' => true,
            'pagibig_enrolled' => true,
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);
});

describe('who is on a run', function (): void {
    it('pays the salaried and leaves the per-trip staff off', function (): void {
        ($this->hire)();
        ($this->hire)([
            'first_name' => 'Marco',
            'last_name' => 'Villanueva',
            'position' => 'Driver',
            'licence_no' => 'N01-23-456789',
            'licence_expires_on' => '2029-08-31',
            'amount_cents' => 0,
        ]);

        $run = ($this->open)()->assertCreated()->json('data');

        expect($run['staff_count'])->toBe(1)
            ->and($run['lines'][0]['name'])->toBe('Elena Bautista')
            ->and($run['period_label'])->toBe('1–15 Sep 2026');
    });

    it('refuses to approve a run with nobody on it', function (): void {
        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/approve")
            ->assertStatus(422);
    });
});

describe('what comes off a payslip', function (): void {
    it('works out all four statutory deductions to the centavo', function (): void {
        ($this->hire)();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(1_500_000)
            ->and($line['sss_cents'])->toBe(75_000)
            ->and($line['philhealth_cents'])->toBe(37_500)
            ->and($line['pagibig_cents'])->toBe(10_000)
            ->and($line['withholding_tax_cents'])->toBe(50_370);
    });

    it('taxes the pay left after the contributions, not the gross', function (): void {
        ($this->hire)();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        $contributions = $line['sss_cents'] + $line['philhealth_cents'] + $line['pagibig_cents'];
        $taxable = $line['basic_cents'] - $contributions;

        expect($line['withholding_tax_cents'])
            ->toBe((int) round(($taxable - 1_041_700) * 0.15));
    });

    it('adds the totals up the way the paper does', function (): void {
        ($this->hire)();

        $run = ($this->open)()->assertCreated()->json('data');
        $line = $run['lines'][0];

        expect($line['gross_cents'])->toBe(1_500_000)
            ->and($line['deductions_cents'])->toBe(172_870)
            ->and($line['net_cents'])->toBe(1_327_130)
            ->and($run['gross_cents'])->toBe($line['gross_cents'])
            ->and($run['net_cents'])->toBe($line['net_cents']);
    });

    it('charges no tax to somebody under the first bracket', function (): void {
        ($this->hire)(['amount_cents' => 1_200_000]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['withholding_tax_cents'])->toBe(0)
            ->and($line['net_cents'])->toBeLessThan($line['gross_cents']);
    });
});

describe('splitting the salary', function (): void {
    it('pays half on each cutoff, and the two halves are the salary', function (): void {
        ($this->hire)(['amount_cents' => 1_000_001]);

        $first = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-15'])
            ->assertCreated()->json('data.lines.0');
        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($first['basic_cents'])->toBe(500_000)
            ->and($second['basic_cents'])->toBe(500_001)
            ->and($first['basic_cents'] + $second['basic_cents'])->toBe(1_000_001);
    });

    it('pays the whole salary on the one run where payroll is monthly', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        ($this->hire)(['amount_cents' => 1_000_001]);

        $line = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(1_000_001);
    });

    it('puts the odd centavo of any monthly figure on the last run', function (): void {
        expect(DeductionSchedule::Split->shareOf(101, index: 0, count: 2))->toBe(50)
            ->and(DeductionSchedule::Split->shareOf(101, index: 1, count: 2))->toBe(51);
    });

    it('splits a month across three runs without inventing a peso', function (): void {
        $shares = array_map(
            fn (int $index): int => DeductionSchedule::Split->shareOf(100_000, $index, 3),
            [0, 1, 2],
        );

        expect($shares)->toBe([33_333, 33_333, 33_334])
            ->and(array_sum($shares))->toBe(100_000);
    });

    it('never charges a contribution twice over three runs', function (): void {
        foreach ([DeductionSchedule::FirstCutoff, DeductionSchedule::SecondCutoff] as $policy) {
            $total = array_sum(array_map(
                fn (int $index): int => $policy->shareOf(100_000, $index, 3),
                [0, 1, 2],
            ));

            expect($total)->toBe(100_000);
        }
    });
});

describe('which cutoff the contributions come off', function (): void {
    beforeEach(function (): void {
        ($this->hire)();

        $this->setSchedule = fn (string $schedule) => $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['payroll_deduct_on' => $schedule])
            ->assertOk();

        $this->firstCutoff = fn () => ($this->open)([
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        ])->assertCreated()->json('data');

        $this->secondCutoff = fn () => ($this->open)([
            'period_start' => '2026-09-16', 'period_end' => '2026-09-30',
        ])->assertCreated()->json('data');
    });

    it('splits them across both cutoffs by default', function (): void {
        $first = ($this->firstCutoff)();

        expect($first['deduct_on'])->toBe('split')
            ->and($first['carries_contributions'])->toBeTrue()
            ->and($first['lines'][0]['sss_cents'])->toBe(75_000)
            ->and($first['lines'][0]['philhealth_cents'])->toBe(37_500)
            ->and($first['lines'][0]['pagibig_cents'])->toBe(10_000);

        expect(($this->secondCutoff)()['lines'][0]['sss_cents'])->toBe(75_000);
    });

    it('takes the whole month on the first cutoff when the firm says so', function (): void {
        ($this->setSchedule)('first');

        $first = ($this->firstCutoff)();
        $second = ($this->secondCutoff)();

        expect($first['carries_contributions'])->toBeTrue()
            ->and($first['lines'][0]['sss_cents'])->toBe(150_000)
            ->and($first['lines'][0]['philhealth_cents'])->toBe(75_000)
            ->and($first['lines'][0]['pagibig_cents'])->toBe(20_000);

        expect($second['carries_contributions'])->toBeFalse()
            ->and($second['lines'][0]['sss_cents'])->toBe(0)
            ->and($second['lines'][0]['philhealth_cents'])->toBe(0)
            ->and($second['lines'][0]['pagibig_cents'])->toBe(0);
    });

    it('takes the whole month on the second cutoff when the firm says so', function (): void {
        ($this->setSchedule)('second');

        expect(($this->firstCutoff)()['lines'][0]['sss_cents'])->toBe(0)
            ->and(($this->secondCutoff)()['lines'][0]['sss_cents'])->toBe(150_000);
    });

    it('remits the same month whichever cutoff carries it', function (): void {
        $monthly = 245_000;

        foreach (['split', 'first', 'second'] as $schedule) {
            ($this->setSchedule)($schedule);

            $firstRun = ($this->firstCutoff)();
            $secondRun = ($this->secondCutoff)();
            $first = $firstRun['statutory'];
            $second = $secondRun['statutory'];

            $taken = $first['sss'] + $first['philhealth'] + $first['pagibig']
                + $second['sss'] + $second['philhealth'] + $second['pagibig'];

            expect($taken)->toBe($monthly);

            $this->actingAs($this->admin)->deleteJson("/api/v1/payroll/{$firstRun['id']}")->assertNoContent();
            $this->actingAs($this->admin)->deleteJson("/api/v1/payroll/{$secondRun['id']}")->assertNoContent();
        }
    });

    it('moves the tax with the contributions, because the BIR charges what is left', function (): void {
        ($this->setSchedule)('first');

        expect(($this->firstCutoff)()['lines'][0]['withholding_tax_cents'])->toBe(31_995)
            ->and(($this->secondCutoff)()['lines'][0]['withholding_tax_cents'])->toBe(68_745);
    });

    it('is the office\'s to set, and says what it means', function (): void {
        $company = ($this->setSchedule)('second')->json('data');

        expect($company['payroll_deduct_on'])->toBe('second')
            ->and($company['payroll_deduct_on_label'])->toBe('All on the second run')
            ->and($company['payroll_deduct_on_detail'])->toContain('The rest carry none.');
    });

    it('refuses a schedule that is not one of the three', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['payroll_deduct_on' => 'quarterly'])
            ->assertStatus(422);
    });
});

describe('what triggers withholding tax', function (): void {
    it('charges nothing to a salary inside the exemption', function (): void {
        ($this->hire)(['amount_cents' => 2_000_000]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['withholding_tax_cents'])->toBe(0)
            ->and($line['sss_cents'])->toBeGreaterThan(0);
    });

    it('charges nothing at exactly the exemption, and something above it', function (): void {
        ($this->hire)(['amount_cents' => 2_083_333]);
        ($this->hire)([
            'first_name' => 'Rosa',
            'last_name' => 'Zamora',
            'amount_cents' => 2_500_000,
        ]);

        $lines = collect(($this->open)()->assertCreated()->json('data.lines'))->keyBy('name');

        expect($lines['Elena Bautista']['withholding_tax_cents'])->toBe(0)
            ->and($lines['Rosa Zamora']['withholding_tax_cents'])->toBe(15_683);
    });

    it('keeps a monthly payroll from taxing an exempt salary', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        ($this->hire)(['amount_cents' => 2_000_000]);

        $exemptRun = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data');
        $exempt = $exemptRun['lines'][0];

        expect($exempt['withholding_tax_cents'])->toBe(0);

        $this->actingAs($this->admin)->deleteJson("/api/v1/payroll/{$exemptRun['id']}")->assertNoContent();

        config(['cargo.payroll.withholding.exempt_monthly_at_or_below_cents' => 0]);

        $taxed = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($taxed['withholding_tax_cents'])->toBe(0);
    });

    it('answers the exemption question on its own', function (): void {
        $deductions = app(StatutoryDeductions::class);

        expect($deductions->isExempt(2_083_333))->toBeTrue()
            ->and($deductions->isExempt(2_083_334))->toBeFalse();
    });
});

describe('correcting a payslip', function (): void {
    it('takes an allowance and reworks the totals from the parts', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');
        $line = $run['lines'][0];

        $updated = $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/{$run['id']}/lines/{$line['id']}", [
                'allowance_cents' => 200_000,
                'other_deductions_cents' => 50_000,
                'deduction_note' => 'Cash advance, 20 Aug',
            ])->assertOk()->json('data.lines.0');

        expect($updated['gross_cents'])->toBe(1_700_000)
            ->and($updated['deductions_cents'])->toBe(222_870)
            ->and($updated['net_cents'])->toBe(1_477_130)
            ->and($updated['withholding_tax_cents'])->toBe(50_370);
    });

    it('will not take a payslip that is not on this run', function (): void {
        ($this->hire)();
        $first = ($this->open)()->assertCreated()->json('data');
        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/{$second['id']}/lines/{$first['lines'][0]['id']}", [
                'allowance_cents' => 1,
            ])->assertNotFound();
    });

    it('works the run out again from the records as they now stand', function (): void {
        $employee = ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$employee['id']}", [
                'first_name' => 'Elena',
                'last_name' => 'Bautista',
                'position' => 'Office Staff',
                'department' => 'Administration',
                'contact' => '0917 555 0199',
                'hired_on' => '2026-01-05',
                'amount_cents' => 3_600_000,
                'effective_from' => '2026-09-01',
            ])->assertOk();

        $rebuilt = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertOk()->json('data');

        expect($rebuilt['staff_count'])->toBe(1)
            ->and($rebuilt['lines'][0]['basic_cents'])->toBe(1_800_000);
    });
});

describe('approving', function (): void {
    it('freezes the figures and offers only paying afterwards', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        $approved = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/approve")
            ->assertOk()->json('data');

        expect($approved['status'])->toBe(PayRun::APPROVED)
            ->and($approved['approved_by_name'])->toBe('Juan Dela Cruz')
            ->and($approved['can_edit'])->toBeFalse()
            ->and($approved['can_pay'])->toBeTrue();
    });

    it('shuts the door on editing, rebuilding and deleting', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');
        $lineId = $run['lines'][0]['id'];

        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/{$run['id']}/lines/{$lineId}", ['allowance_cents' => 1])
            ->assertStatus(422);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertStatus(422);
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/{$run['id']}")
            ->assertStatus(422);
    });

    it('will not pay a run nobody has approved', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/pay")
            ->assertStatus(422);
    });
});

describe('paying puts it in the books', function (): void {
    beforeEach(function (): void {
        ($this->hire)();
        $this->run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$this->run['id']}/approve")->assertOk();

        $this->settle = fn () => $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$this->run['id']}/pay");
    });

    it('writes the entry an accountant would write by hand', function (): void {
        $paid = ($this->settle)()->assertOk()->json('data');

        expect($paid['status'])->toBe(PayRun::PAID)
            ->and($paid['journal_entry_id'])->not->toBeNull()
            ->and($paid['journal_reference'])->toStartWith('JV-');

        $entry = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/journal/{$paid['journal_entry_id']}")
            ->assertOk()->json('data');

        expect($entry['status'])->toBe(JournalEntry::POSTED)
            ->and($entry['category'])->toBe('payroll');

        $lines = collect($entry['lines']);
        $byCode = $lines->keyBy('account_code');

        expect($lines->where('account_code', '5200')->pluck('debit_cents')->all())->toBe([1_500_000, 199_000])
            ->and($lines->where('account_code', '2200')->pluck('credit_cents')->all())->toBe([122_500, 199_000])
            ->and($byCode['2160']['credit_cents'])->toBe(50_370)
            ->and($byCode['1020']['credit_cents'])->toBe(1_327_130);
    });

    it('leaves the trial balance balanced', function (): void {
        ($this->settle)()->assertOk();

        $trial = $this->actingAs($this->accountant)
            ->getJson('/api/v1/accounting/ledger/trial-balance')
            ->assertOk()->json('data');

        expect($trial['balanced'])->toBeTrue()
            ->and($trial['difference_cents'])->toBe(0)
            ->and($trial['debit_total_cents'])->toBe(1_500_000 + 199_000);
    });

    it('sends a cash advance against wages payable rather than out of cash', function (): void {
        $run = ($this->open)([
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-15',
            'pay_date' => '2026-10-15',
        ])->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/{$run['id']}/lines/{$run['lines'][0]['id']}", [
                'other_deductions_cents' => 40_000,
                'deduction_note' => 'Advance recovered',
            ])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();
        $paid = $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/pay")
            ->assertOk()->json('data');

        $lines = collect(
            $this->actingAs($this->accountant)
                ->getJson("/api/v1/accounting/journal/{$paid['journal_entry_id']}")
                ->json('data.lines')
        )->keyBy('account_code');

        expect($lines['2100']['credit_cents'])->toBe(40_000)
            ->and($lines['1020']['credit_cents'])->toBe(1_287_130);
    });

    it('cannot be paid twice', function (): void {
        ($this->settle)()->assertOk();
        ($this->settle)()->assertStatus(422);

        expect(JournalEntry::query()->where('source', 'payroll')->count())->toBe(1);
    });

    it('stays shut to every write once it is in the books', function (): void {
        ($this->settle)()->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$this->run['id']}/rebuild")
            ->assertStatus(422);
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/{$this->run['id']}")
            ->assertStatus(422);
    });
});

describe('who may run it', function (): void {
    it('keeps a driver out of the payslips', function (): void {
        $this->actingAs($this->driverUser)->getJson('/api/v1/payroll')->assertForbidden();
    });

    it('lets an accountant run it', function (): void {
        ($this->hire)();

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/payroll', [
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-15',
                'pay_date' => '2026-09-15',
            ])->assertCreated();
    });

    it('refuses a period that ends before it starts', function (): void {
        ($this->open)(['period_end' => '2026-08-01'])->assertStatus(422);
    });
});

describe('the cutoff', function (): void {
    it('takes the first half of a month', function (): void {
        ($this->hire)();

        $run = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-15'])
            ->assertCreated()->json('data');

        expect($run['period_label'])->toBe('1–15 Sep 2026');
    });

    it('takes the second half, whatever length the month is', function (): void {
        ($this->hire)();

        foreach ([
            ['2026-09-16', '2026-09-30', '16–30 Sep 2026'],
            ['2026-10-16', '2026-10-31', '16–31 Oct 2026'],
            ['2026-02-16', '2026-02-28', '16–28 Feb 2026'],
        ] as [$start, $end, $label]) {
            $run = ($this->open)(['period_start' => $start, 'period_end' => $end])
                ->assertCreated()->json('data');

            expect($run['period_label'])->toBe($label);
        }
    });

    it('takes 16–29 in a leap February', function (): void {
        ($this->hire)();

        $run = ($this->open)(['period_start' => '2028-02-16', 'period_end' => '2028-02-29'])
            ->assertCreated()->json('data');

        expect($run['period_label'])->toBe('16–29 Feb 2028');
    });

    it('refuses a fortnight that is not one of the two', function (): void {
        ($this->hire)();

        foreach ([
            ['2026-09-01', '2026-09-20'],
            ['2026-09-03', '2026-09-15'],
            ['2026-09-16', '2026-09-29'],
            ['2026-09-01', '2026-09-30'],
            ['2026-09-16', '2026-10-15'],
        ] as [$start, $end]) {
            ($this->open)(['period_start' => $start, 'period_end' => $end])
                ->assertStatus(422)
                ->assertJsonPath(
                    'errors.period_start.0',
                    'Payroll is cut off on the 15th and the last day of the month here, so a pay period runs 1–15 Sep 2026 or 16–30 Sep 2026. Choose one of those.',
                );
        }
    });

    it('leaves the pay date alone — paying late is not the same as a wrong period', function (): void {
        ($this->hire)();

        $run = ($this->open)([
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-20',
        ])->assertCreated()->json('data');

        expect($run['pay_date'])->toBe('2026-09-20');
    });

    it('still rebuilds a run whose period predates the rule', function (): void {
        ($this->hire)();

        $legacy = app(PayrollService::class)->build(
            Carbon::parse('2026-08-03'),
            Carbon::parse('2026-08-22'),
            Carbon::parse('2026-08-25'),
        );

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$legacy->getKey()}/rebuild")
            ->assertOk()
            ->assertJsonPath('data.staff_count', 1);
    });

    it('makes the whole month the only period when payroll runs monthly', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        ($this->hire)();

        ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated();

        ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-15'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.period_start.0',
                'Payroll runs once a month here, cut off on the last day of the month, so a pay period is 1–30 Sep 2026. Choose that.',
            );
    });
});

describe('offering the choice', function (): void {
    it('lists the two halves of a month, with the day each is cut off', function (): void {
        $periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/periods?month=2026-09')
            ->assertOk()->json('data');

        expect($periods)->toHaveCount(2);

        expect($periods[0])->toMatchArray([
            'half' => 'first',
            'start' => '2026-09-01',
            'end' => '2026-09-15',
            'label' => '1–15 Sep 2026',
            'short' => '1–15',
            'cutoff' => '2026-09-15',
            'days' => 15,
        ]);

        expect($periods[1])->toMatchArray([
            'half' => 'second',
            'start' => '2026-09-16',
            'end' => '2026-09-30',
            'cutoff' => '2026-09-30',
            'days' => 15,
        ]);
    });

    it('reads February off the calendar', function (): void {
        $periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/periods?month=2026-02')
            ->assertOk()->json('data');

        expect($periods[1]['end'])->toBe('2026-02-28')
            ->and($periods[1]['days'])->toBe(13)
            ->and($periods[1]['cutoff'])->toBe('2026-02-28');
    });

    it('suggests the period that has just closed', function (): void {
        $this->travelTo('2026-10-03', function (): void {
            $body = $this->actingAs($this->admin)
                ->getJson('/api/v1/payroll/periods')
                ->assertOk()->json();

            expect($body['meta']['month'])->toBe('2026-09');

            $suggested = collect($body['data'])->firstWhere('suggested', true);

            expect($suggested['start'])->toBe('2026-09-16')
                ->and($suggested['end'])->toBe('2026-09-30');
        });

        $this->travelTo('2026-10-18', function (): void {
            $suggested = collect(
                $this->actingAs($this->admin)->getJson('/api/v1/payroll/periods')->json('data')
            )->firstWhere('suggested', true);

            expect($suggested['start'])->toBe('2026-10-01')
                ->and($suggested['end'])->toBe('2026-10-15');
        });
    });

    it('offers one period a month to an office that pays monthly', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        $periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/periods?month=2026-09')
            ->assertOk()->json('data');

        expect($periods)->toHaveCount(1)
            ->and($periods[0]['half'])->toBe('month')
            ->and($periods[0]['start'])->toBe('2026-09-01')
            ->and($periods[0]['end'])->toBe('2026-09-30');
    });

    it('treats an unreadable month as the one that is due', function (): void {
        $this->travelTo('2026-10-03', function (): void {
            $body = $this->actingAs($this->admin)
                ->getJson('/api/v1/payroll/periods?month=2026-13-45')
                ->assertOk()->json();

            expect($body['meta']['month'])->toBe('2026-09');
        });
    });

    it('is not readable by somebody without the payroll permission', function (): void {
        $this->actingAs($this->driverUser)->getJson('/api/v1/payroll/periods')->assertForbidden();
    });
});

describe('the reference', function (): void {
    it('numbers runs within the year and does not reuse a deleted number', function (): void {
        ($this->hire)();

        $first = ($this->open)()->assertCreated()->json('data.reference');
        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data');

        expect($first)->toBe('PR-2026-0001')
            ->and($second['reference'])->toBe('PR-2026-0002');

        $this->actingAs($this->admin)->deleteJson("/api/v1/payroll/{$second['id']}")->assertNoContent();

        $third = ($this->open)(['period_start' => '2026-10-01', 'period_end' => '2026-10-15'])
            ->assertCreated()->json('data.reference');

        expect($third)->toBe('PR-2026-0003');
    });
});
