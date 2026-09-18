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

/**
 * Payroll: a fortnight's pay, frozen, handed out, and put in the books.
 *
 * What these tests are actually defending:
 *
 *   **The figures are worked out, not typed.** A run is built from the employee
 *   records, and every statutory deduction on it comes from the rate table in
 *   `config/cargo.php`. The arithmetic is asserted to the centavo here because
 *   a payslip is a promise to a person and to the government both.
 *
 *   **The BIR's order.** Tax is charged on gross *less* the three
 *   contributions. Getting that backwards overstates the tax on every payslip,
 *   which is why the order has a test of its own and not just the total.
 *
 *   **Approving freezes it.** Payslips go out at approval, so nothing may move
 *   afterwards — not a line, not a rebuild, not a delete.
 *
 *   **Paying posts it.** A paid run writes the journal entry an accountant
 *   would write by hand, the trial balance still balances afterwards, and the
 *   same run can never post twice.
 */
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

    /**
     * Somebody on a monthly basic, which is who payroll is for.
     *
     * PHP 30,000 a month is chosen because it lands in the middle of every rate
     * band — above the PhilHealth floor, below the SSS ceiling, over the
     * Pag-IBIG cap, and inside the second withholding bracket — so one salary
     * exercises all four rules at once.
     */
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
        // A driver paid per trip: their money is already in the daily truck
        // sheet's driver column, so paying them here would pay them twice.
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
            // How the period reads on a list and at the head of the register.
            // Sent rather than assembled by a client, so both clients say the
            // same thing about the same fortnight.
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

        // Half a month of PHP 30,000.
        expect($line['basic_cents'])->toBe(1_500_000)
            // 4.5% of the monthly basic, halved onto this run.
            ->and($line['sss_cents'])->toBe(67_500)
            // 2.5%, halved.
            ->and($line['philhealth_cents'])->toBe(37_500)
            // The PHP 200 cap, halved — which is what most payslips show.
            ->and($line['pagibig_cents'])->toBe(10_000)
            // 15% of the taxable pay above PHP 10,417 for the period.
            ->and($line['withholding_tax_cents'])->toBe(51_495);
    });

    it('taxes the pay left after the contributions, not the gross', function (): void {
        ($this->hire)();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        $contributions = $line['sss_cents'] + $line['philhealth_cents'] + $line['pagibig_cents'];
        $taxable = $line['basic_cents'] - $contributions;

        // The BIR's order. Taxing the gross instead would charge more on every
        // fortnight, on every payslip, for as long as the mistake stood.
        expect($line['withholding_tax_cents'])
            ->toBe((int) round(($taxable - 1_041_700) * 0.15));
    });

    it('adds the totals up the way the paper does', function (): void {
        ($this->hire)();

        $run = ($this->open)()->assertCreated()->json('data');
        $line = $run['lines'][0];

        expect($line['gross_cents'])->toBe(1_500_000)
            ->and($line['deductions_cents'])->toBe(166_495)
            ->and($line['net_cents'])->toBe(1_333_505)
            // And the run is the sum of its payslips, nothing else.
            ->and($run['gross_cents'])->toBe($line['gross_cents'])
            ->and($run['net_cents'])->toBe($line['net_cents']);
    });

    it('charges no tax to somebody under the first bracket', function (): void {
        // A PHP 12,000 monthly basic: PHP 6,000 a period, which is under the
        // PHP 10,417 floor of the first taxable bracket.
        ($this->hire)(['amount_cents' => 1_200_000]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['withholding_tax_cents'])->toBe(0)
            // The contributions still come off, though.
            ->and($line['net_cents'])->toBeLessThan($line['gross_cents']);
    });
});

/**
 * The monthly salary, split across the month's two cutoffs.
 *
 * Two payslips have to add up to the salary somebody was promised — exactly,
 * every month, for years. Halving with `intdiv` on both runs shorts a salary
 * ending in an odd centavo by a centavo a month: small, permanent, and
 * impossible to find from either payslip.
 */
describe('splitting the salary', function (): void {
    it('pays half on each cutoff, and the two halves are the salary', function (): void {
        // ₱10,000.01 a month — an odd number of centavos, which is where a
        // careless split loses one.
        ($this->hire)(['amount_cents' => 1_000_001]);

        $first = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-15'])
            ->assertCreated()->json('data.lines.0');
        $second = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($first['basic_cents'])->toBe(500_000)
            // The remainder lands on the second cutoff.
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

    it('puts the odd centavo of any monthly figure on the second cutoff', function (): void {
        // The rule the salary and every contribution share, stated once.
        expect(DeductionSchedule::Split->shareOf(101, isFirstCutoff: true))->toBe(50)
            ->and(DeductionSchedule::Split->shareOf(101, isFirstCutoff: false))->toBe(51);
    });
});

/**
 * Which cutoff the contributions come off — the firm's own setting.
 *
 * SSS, PhilHealth and Pag-IBIG are monthly figures and payroll runs twice a
 * month, so something has to decide where they land. All three answers remit
 * the same amount to the same agency; what changes is which payslip is lighter,
 * which is why it is the firm's decision and not a rate.
 */
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
            ->and($first['lines'][0]['sss_cents'])->toBe(67_500)
            ->and($first['lines'][0]['philhealth_cents'])->toBe(37_500)
            ->and($first['lines'][0]['pagibig_cents'])->toBe(10_000);

        expect(($this->secondCutoff)()['lines'][0]['sss_cents'])->toBe(67_500);
    });

    it('takes the whole month on the first cutoff when the firm says so', function (): void {
        ($this->setSchedule)('first');

        $first = ($this->firstCutoff)();
        $second = ($this->secondCutoff)();

        // The whole month of contributions on the first payslip…
        expect($first['carries_contributions'])->toBeTrue()
            ->and($first['lines'][0]['sss_cents'])->toBe(135_000)
            ->and($first['lines'][0]['philhealth_cents'])->toBe(75_000)
            ->and($first['lines'][0]['pagibig_cents'])->toBe(20_000);

        // …and none at all on the second, which is the point of the setting.
        expect($second['carries_contributions'])->toBeFalse()
            ->and($second['lines'][0]['sss_cents'])->toBe(0)
            ->and($second['lines'][0]['philhealth_cents'])->toBe(0)
            ->and($second['lines'][0]['pagibig_cents'])->toBe(0);
    });

    it('takes the whole month on the second cutoff when the firm says so', function (): void {
        ($this->setSchedule)('second');

        expect(($this->firstCutoff)()['lines'][0]['sss_cents'])->toBe(0)
            ->and(($this->secondCutoff)()['lines'][0]['sss_cents'])->toBe(135_000);
    });

    it('remits the same month whichever cutoff carries it', function (): void {
        $monthly = 230_000;

        foreach (['split', 'first', 'second'] as $schedule) {
            ($this->setSchedule)($schedule);

            $first = ($this->firstCutoff)()['statutory'];
            $second = ($this->secondCutoff)()['statutory'];

            $taken = $first['sss'] + $first['philhealth'] + $first['pagibig']
                + $second['sss'] + $second['philhealth'] + $second['pagibig'];

            // The agencies get their month either way. The setting decides
            // which payslip is lighter, not what is owed.
            expect($taken)->toBe($monthly);
        }
    });

    it('moves the tax with the contributions, because the BIR charges what is left', function (): void {
        ($this->setSchedule)('first');

        // Taxable pay is the gross less the contributions actually taken on
        // *this* cutoff, so the loaded payslip is taxed less and the empty one
        // more.
        expect(($this->firstCutoff)()['lines'][0]['withholding_tax_cents'])->toBe(34_245)
            ->and(($this->secondCutoff)()['lines'][0]['withholding_tax_cents'])->toBe(68_745);
    });

    it('is the office\'s to set, and says what it means', function (): void {
        $company = ($this->setSchedule)('second')->json('data');

        expect($company['payroll_deduct_on'])->toBe('second')
            ->and($company['payroll_deduct_on_label'])
            ->toBe('All on the second cutoff (16th–end of month)')
            ->and($company['payroll_deduct_on_detail'])->toContain('The first carries none.');
    });

    it('refuses a schedule that is not one of the three', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['payroll_deduct_on' => 'quarterly'])
            ->assertStatus(422);
    });
});

/**
 * What salary range triggers withholding tax.
 *
 * ₱250,000 a year is exempt under TRAIN — ₱20,833.33 a month — and the check is
 * made against the **monthly basic** rather than one period's gross. A person's
 * salary decides whether they are a taxpayer at all; the bracket table only
 * decides how much once they are. Reading it off a single fortnight instead
 * makes payroll look arbitrary to the person receiving it.
 */
describe('what triggers withholding tax', function (): void {
    it('charges nothing to a salary inside the exemption', function (): void {
        ($this->hire)(['amount_cents' => 2_000_000]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['withholding_tax_cents'])->toBe(0)
            // The three contributions still come off — they are not a tax.
            ->and($line['sss_cents'])->toBeGreaterThan(0);
    });

    it('charges nothing at exactly the exemption, and something above it', function (): void {
        // ₱20,833.33 a month is ₱250,000 a year: the last salary that pays no
        // income tax.
        ($this->hire)(['amount_cents' => 2_083_333]);
        ($this->hire)([
            'first_name' => 'Rosa',
            'last_name' => 'Zamora',
            'amount_cents' => 2_500_000,
        ]);

        $lines = collect(($this->open)()->assertCreated()->json('data.lines'))->keyBy('name');

        expect($lines['Elena Bautista']['withholding_tax_cents'])->toBe(0)
            ->and($lines['Rosa Zamora']['withholding_tax_cents'])->toBe(16_620);
    });

    /**
     * Where the rule earns its keep: a monthly payroll, whose whole month of
     * pay would otherwise be read against a semi-monthly bracket table and
     * taxed as though the person earned twice what they do.
     */
    it('keeps a monthly payroll from taxing an exempt salary', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        ($this->hire)(['amount_cents' => 2_000_000]);

        $exempt = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($exempt['withholding_tax_cents'])->toBe(0);

        // Turn the exemption off and the table alone charges this salary a
        // tax it does not owe — which is the failure the rule exists to stop.
        config(['cargo.payroll.withholding.exempt_monthly_at_or_below_cents' => 0]);

        $taxed = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');

        expect($taxed['withholding_tax_cents'])->toBeGreaterThan(0);
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
            ->and($updated['deductions_cents'])->toBe(216_495)
            ->and($updated['net_cents'])->toBe(1_483_505)
            // Deliberately unmoved: an allowance is a one-off, and the table
            // would tax it as if it were the person's regular pay.
            ->and($updated['withholding_tax_cents'])->toBe(51_495);
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
                // Backdated to the start of the period: this is a correction to
                // a figure that was always wrong, not a rise from today. A rise
                // would be left to default, and would not touch this run.
                'effective_from' => '2026-09-01',
            ])->assertOk();

        $rebuilt = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertOk()->json('data');

        // One line, not two: rebuilding replaces the lines rather than adding
        // to them, or somebody ends up paid from two calculations.
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
            // The screen prints this on a paid run — it is the line that says
            // payroll reached the accounts rather than stopping at a
            // spreadsheet, so it has to come back with the run and not need a
            // second call.
            ->and($paid['journal_reference'])->toStartWith('JV-');

        $entry = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/journal/{$paid['journal_entry_id']}")
            ->assertOk()->json('data');

        expect($entry['status'])->toBe(JournalEntry::POSTED)
            ->and($entry['category'])->toBe('payroll');

        $byCode = collect($entry['lines'])->keyBy('account_code');

        // The whole cost of employing people, on one debit.
        expect($byCode['5200']['debit_cents'])->toBe(1_500_000)
            // What each agency is now owed, kept apart because each is remitted
            // on its own form.
            ->and($byCode['2200']['credit_cents'])->toBe(115_000)
            ->and($byCode['2160']['credit_cents'])->toBe(51_495)
            // And what the staff were actually handed.
            ->and($byCode['1020']['credit_cents'])->toBe(1_333_505);
    });

    it('leaves the trial balance balanced', function (): void {
        ($this->settle)()->assertOk();

        $trial = $this->actingAs($this->accountant)
            ->getJson('/api/v1/accounting/ledger/trial-balance')
            ->assertOk()->json('data');

        expect($trial['balanced'])->toBeTrue()
            ->and($trial['difference_cents'])->toBe(0)
            // And it is balanced because there is something on it, not because
            // the books are empty.
            ->and($trial['debit_total_cents'])->toBe(1_500_000);
    });

    it('sends a cash advance against wages payable rather than out of cash', function (): void {
        // A second run, because the first is already approved and frozen.
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
            // Cash goes down by the net only — the advance never left.
            ->and($lines['1020']['credit_cents'])->toBe(1_293_505);
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

/**
 * The cutoff: payroll runs on the 1st and the 16th, so a period is the 1st to
 * the 15th or the 16th to the end of the month.
 *
 * Not a default — a rule. The statutory figures on every payslip are half a
 * month's contributions and a *semi-monthly* tax table, so a run covering the
 * 3rd to the 20th would take half a month's SSS off eighteen days of pay and
 * tax it on a table built for fifteen. Wrong twice, and wrong invisibly, which
 * is why the shape is refused rather than trusted.
 */
describe('the cutoff', function (): void {
    it('takes the first half of a month', function (): void {
        ($this->hire)();

        $run = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-15'])
            ->assertCreated()->json('data');

        expect($run['period_label'])->toBe('1–15 Sep 2026');
    });

    it('takes the second half, whatever length the month is', function (): void {
        ($this->hire)();

        // Thirty days, thirty-one days, and a non-leap February — the end of
        // the period is read off the calendar rather than written as a number.
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

        // Every one of these looks like a reasonable pay period and is not one.
        foreach ([
            ['2026-09-01', '2026-09-20'],
            ['2026-09-03', '2026-09-15'],
            ['2026-09-16', '2026-09-29'],
            ['2026-09-01', '2026-09-30'],
            // Crossing a month boundary, which no period on *this* firm's
            // calendar does. One cutting off on the 10th and the 25th has a
            // period that does — see the cutoff-days tests below.
            ['2026-09-16', '2026-10-15'],
        ] as [$start, $end]) {
            ($this->open)(['period_start' => $start, 'period_end' => $end])
                ->assertStatus(422)
                ->assertJsonPath(
                    'errors.period_start.0',
                    // The message names the firm's own cutoff days, because
                    // they are now the firm's own setting and an office that
                    // moved them may have forgotten doing so.
                    'Payroll is cut off on the 15th and the last day of the month here, so a pay period runs 1–15 Sep 2026 or 16–30 Sep 2026. Choose one of those.',
                );
        }
    });

    it('leaves the pay date alone — paying late is not the same as a wrong period', function (): void {
        ($this->hire)();

        // Closed on the 16th, paid on the 20th. Ordinary, and the books care
        // when the money moved rather than when the period ended.
        $run = ($this->open)([
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-20',
        ])->assertCreated()->json('data');

        expect($run['pay_date'])->toBe('2026-09-20');
    });

    /**
     * The rule is enforced at the boundary, and deliberately not inside the
     * service — so a run opened before the rule existed can still be worked
     * out again rather than being stranded.
     */
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
            // Cut off on the 16th, which is when this run is worked out.
            'cutoff' => '2026-09-16',
            'days' => 15,
        ]);

        expect($periods[1])->toMatchArray([
            'half' => 'second',
            'start' => '2026-09-16',
            'end' => '2026-09-30',
            // Cut off on the 1st of the next month.
            'cutoff' => '2026-10-01',
            'days' => 15,
        ]);
    });

    it('reads February off the calendar', function (): void {
        $periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/periods?month=2026-02')
            ->assertOk()->json('data');

        expect($periods[1]['end'])->toBe('2026-02-28')
            ->and($periods[1]['days'])->toBe(13)
            ->and($periods[1]['cutoff'])->toBe('2026-03-01');
    });

    /**
     * On a cutoff day, the run an office wants is the period that has just
     * closed — which is last month's back half for the first half of a month.
     */
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

            // Past the 16th, the first half of this month is the one that has
            // closed.
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

        // The deleted draft keeps its number: PR-2026-0002 may have been quoted
        // on a payslip somewhere, even if that run went away.
        $third = ($this->open)(['period_start' => '2026-10-01', 'period_end' => '2026-10-15'])
            ->assertCreated()->json('data.reference');

        expect($third)->toBe('PR-2026-0003');
    });
});
