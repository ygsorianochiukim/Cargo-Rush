<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\StoreCredit;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The mini-mart tab — *pautang* — and the statutory switches beside it.
 *
 * Two features that arrive together because they are the same complaint: the
 * office could not say "not this person" or "not this much" about what comes
 * off a payslip, and had to retype a correction every cutoff instead.
 *
 * What these are defending:
 *
 *   **A tab is a balance, not an instalment plan.** It changes every time
 *   somebody buys a sack of rice, and the cutoff takes what is there. A pay
 *   component cannot express that, which is why this is not one.
 *
 *   **A draft can be rebuilt.** The repayment is written on *approve*, never on
 *   build — otherwise pressing "work out again" twice would settle the tab
 *   twice and hand the person the difference. This is the test that matters
 *   most, because the bug it prevents is money.
 *
 *   **The payslip is floored at zero.** A recovery that takes more than the
 *   payslip holds has stopped being a recovery.
 *
 *   **A contribution somebody is not enrolled for is not taken** — and the
 *   payslip says which, because "SSS ₱0.00" has two explanations and only one
 *   of them is for the office to fix.
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

    /** Put a line on somebody's tab. */
    $this->charge = fn (string $employeeId, array $overrides = []) => $this->actingAs($this->admin)
        ->postJson("/api/v1/employees/{$employeeId}/store-credits", [
            'kind' => 'charge',
            'amount_cents' => 50_000,
            'description' => '3 kg rice',
            'outlet' => 'Mini mart',
            'charged_on' => '2026-09-05',
            ...$overrides,
        ]);

    $this->tab = fn (string $employeeId) => $this->actingAs($this->admin)
        ->getJson("/api/v1/employees/{$employeeId}/store-credits");

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);
});

describe('keeping the tab', function (): void {
    it('adds a charge and shows the balance behind it', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'])->assertCreated();
        ($this->charge)($employee['id'], ['amount_cents' => 30_000, 'description' => '2 tins'])
            ->assertCreated();

        $tab = ($this->tab)($employee['id'])->assertOk();

        expect($tab->json('data'))->toHaveCount(2);
        expect($tab->json('meta.balance_cents'))->toBe(80_000);
    });

    it('takes a cash repayment off the balance', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'])->assertCreated();
        ($this->charge)($employee['id'], ['kind' => 'payment', 'amount_cents' => 20_000])
            ->assertCreated();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(30_000);
    });

    it('never reports a negative balance', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'], ['amount_cents' => 10_000])->assertCreated();
        ($this->charge)($employee['id'], ['kind' => 'payment', 'amount_cents' => 25_000])
            ->assertCreated();

        // An overpaid tab is a credit the *store* owes, and handing it back
        // through a negative payroll deduction would be an addition to a
        // payslip dressed as a recovery.
        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(0);
    });

    it('refuses a row that moves nothing', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'], ['amount_cents' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_cents');
    });

    it('lets the storekeeper remove a line they mis-keyed', function (): void {
        $employee = ($this->hire)();
        $row = ($this->charge)($employee['id'])->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/store-credits/{$row['id']}")
            ->assertNoContent();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(0);
    });
});

describe('what a payslip takes off the tab', function (): void {
    it('takes the whole balance when no cap is set', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['store_deduction_cents'])->toBe(120_000);
        // And it is in the deductions, so the net is short by exactly that.
        expect($line['deductions_cents'] - 120_000)
            ->toBe($line['sss_cents'] + $line['philhealth_cents'] + $line['pagibig_cents']
                + $line['withholding_tax_cents']);
    });

    it('takes only the cap where the person has one', function (): void {
        $employee = ($this->hire)(['store_deduction_cap_cents' => 50_000]);
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        expect(($this->open)()->json('data.lines.0.store_deduction_cents'))->toBe(50_000);
    });

    it('ignores a charge dated after the period it is paying', function (): void {
        $employee = ($this->hire)();

        // The run covers 1–15 Sep. A charge on the 18th belongs to the next
        // payslip, and a rebuild on the 20th must not reach back for it — that
        // would move a figure the office had already checked.
        ($this->charge)($employee['id'], ['charged_on' => '2026-09-18', 'amount_cents' => 90_000])
            ->assertCreated();

        expect(($this->open)()->json('data.lines.0.store_deduction_cents'))->toBe(0);
    });

    it('never takes more than the payslip can bear', function (): void {
        // A day-rate hand with a small payslip and a large tab.
        $employee = ($this->hire)([
            'first_name' => 'Nita',
            'pay_basis' => 'monthly',
            'amount_cents' => 800_000,
        ]);

        ($this->charge)($employee['id'], ['amount_cents' => 5_000_000])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines');
        $hers = collect($line)->firstWhere('employee_id', $employee['id']);

        // Floored at what is left, so nobody is handed a negative payslip —
        // the rest stays on the tab for the next one.
        expect($hers['net_cents'])->toBe(0);
        expect($hers['store_deduction_cents'])->toBeLessThan(5_000_000);
    });
});

describe('settling the tab', function (): void {
    it('writes no repayment while the run is a draft', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        ($this->open)()->assertCreated();

        // Still owed: a draft is a proposal, and a tab settled by a proposal
        // would be settled again by the next one.
        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(120_000);
    });

    it('survives the draft being worked out again without double-settling', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');

        // The bug this prevents is money: a repayment written at build time
        // would be off the balance the rebuild reads, so the second pass would
        // deduct nothing and the person would keep the difference.
        $again = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertOk()->json('data');

        expect($again['lines'][0]['store_deduction_cents'])->toBe(120_000);
    });

    it('settles the tab when the run is approved', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/approve")
            ->assertOk();

        $tab = ($this->tab)($employee['id'])->assertOk();

        expect($tab->json('meta.balance_cents'))->toBe(0);
        // And the repayment says which payslip took it.
        $payment = collect($tab->json('data'))->firstWhere('kind', 'payment');
        expect($payment['from_payroll'])->toBeTrue();
        expect($payment['amount_cents'])->toBe(120_000);
    });

    it('will not let a payroll repayment be deleted from the tab', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $payment = StoreCredit::query()->whereNotNull('pay_run_line_id')->firstOrFail();

        // It is on a payslip somebody has been handed. Removing it here would
        // leave the tab and the payslip disagreeing with nothing to say which
        // is right; the way to undo one is a correcting charge.
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/store-credits/{$payment->id}")
            ->assertStatus(422);
    });

    it('leaves the rest on the tab when a cap only took part of it', function (): void {
        $employee = ($this->hire)(['store_deduction_cap_cents' => 50_000]);
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        // A tab settles itself over as many payslips as it takes, with no
        // schedule for anybody to maintain.
        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(70_000);
    });
});

describe('leaving somebody out of a contribution', function (): void {
    it('deducts all three by default, as it always did', function (): void {
        ($this->hire)();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['sss_cents'])->toBeGreaterThan(0);
        expect($line['philhealth_cents'])->toBeGreaterThan(0);
        expect($line['pagibig_cents'])->toBeGreaterThan(0);
        expect($line['sss_enrolled'])->toBeTrue();
    });

    it('takes nothing for an agency the person is not enrolled with', function (): void {
        ($this->hire)(['sss_enrolled' => false]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['sss_cents'])->toBe(0);
        // The others are untouched — this is per agency, not a blanket switch.
        expect($line['philhealth_cents'])->toBeGreaterThan(0);
        expect($line['pagibig_cents'])->toBeGreaterThan(0);
    });

    it('freezes which contributions applied onto the payslip', function (): void {
        $employee = ($this->hire)(['pagibig_enrolled' => false]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');
        expect($line['pagibig_enrolled'])->toBeFalse();

        // Enrol them afterwards. Last fortnight's payslip must not start
        // claiming they were enrolled then.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['pagibig_enrolled' => true])
            ->assertOk();

        expect($this->actingAs($this->admin)->getJson('/api/v1/payroll')
            ->json('data.0.lines.0.pagibig_enrolled'))->toBeFalse();
    });

    it('has no switch for withholding tax', function (): void {
        // Whether somebody is taxed is not the firm's to choose. The module
        // answers it from the BIR's exemption threshold instead.
        $employee = ($this->hire)();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['withholding_enrolled' => false])
            ->assertOk();

        expect(Employee::findOrFail($employee['id'])->getAttributes())
            ->not->toHaveKey('withholding_enrolled');
    });
});

describe('putting a run with recoveries into the books', function (): void {
    it('posts a paid run that has a store deduction on it', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        /**
         * The journal debits the whole gross and credits the agencies, what the
         * firm recovered, and the net. It only balances if every deduction that
         * is not a contribution or the tax reaches the recovery bucket.
         *
         * `PayRun::statutoryCents()['other']` used to be `other_deductions_cents`
         * alone, so a run carrying a **pay component deduction** came out short
         * by that amount and `JournalService` refused to post it — with a
         * balance error naming no cause. Nothing tested paying a run that had
         * one. This covers the store deduction and the test below covers the
         * component that exposed it.
         */
        $paid = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/pay")
            ->assertOk();

        expect($paid->json('data.status'))->toBe('paid');
        // Posted, which is the half that was at risk: an unbalanced entry is
        // refused outright, so a journal reference here is the proof that the
        // recovery reached the credit side.
        expect($paid->json('data.journal_entry_id'))->not->toBeNull();
        expect($paid->json('data.journal_reference'))->not->toBeNull();
    });

    it('posts a paid run that has a pay component deduction on it', function (): void {
        ($this->hire)();

        $component = $this->actingAs($this->admin)->postJson('/api/v1/payroll/components', [
            'name' => 'Uniform',
            'kind' => 'deduction',
            'basis' => 'fixed',
            'amount_cents' => 40_000,
            'schedule' => 'each_run',
        ])->assertCreated()->json('data');

        $employee = Employee::query()->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/v1/payroll/assignments', [
            'employee_id' => $employee->getKey(),
            'pay_component_id' => $component['id'],
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/pay")
            ->assertOk();
    });
});
