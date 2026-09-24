<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things an office asks payroll for the week after it starts using it:
 * leave somebody out of a contribution, and take the mini-mart tab off their
 * pay.
 *
 * ## 1. SSS, PhilHealth and Pag-IBIG become optional per person
 *
 * `StatutoryDeductions` charged all three to everybody who reached a payslip.
 * That is right for regular staff and wrong for a good part of a fleet's
 * roster: somebody not yet registered with the agency, a casual hand taken on
 * for the season, a person already contributing through another employer, or a
 * per-trip driver the firm remits for separately. For each of them the system
 * withheld money the firm then had to hand back, and the office's only escape
 * was to zero the figure on every payslip, every cutoff, for ever.
 *
 * So each contribution gets a switch on the employee, and **on** is the
 * default — every person already on the roster keeps exactly the payslip they
 * had. Withholding tax gets no switch, deliberately: whether somebody is taxed
 * is not the firm's to choose, and the module already answers it properly with
 * the BIR's exemption threshold (see `StatutoryDeductions::isExempt()`).
 *
 * The three switches are **copied onto the payslip** as well, like the pay
 * basis and the day count beside them. A payslip reading "SSS ₱0.00" is a line
 * the person holding it will ask about, and "you are not enrolled" and "your
 * basic was nil this fortnight" are different answers. Reading the employee
 * record back would give last March's payslip today's answer.
 *
 * ## 2. The mini-mart tab — *pautang* — as a ledger
 *
 * A yard with a store in it runs a tab: people take goods against their pay
 * and it comes off at the cutoff. Nothing here could express that. A pay
 * component is a standing instruction for a fixed amount, which is the right
 * shape for a loan repayment and the wrong one for a tab, because a tab is a
 * **running balance** that changes every time somebody buys a sack of rice.
 *
 * `store_credits` is that ledger: one row per charge and one per repayment,
 * per person. The balance is the difference, and payroll takes it off.
 *
 * ### Why the repayment is a row rather than a flag on the charges
 *
 * Because a repayment is rarely the whole of anything. A cutoff takes ₱500 off
 * a ₱1,340 tab and the rest rolls on; marking charges paid would mean deciding
 * which ₱500 of the rice and the cooking oil had been settled, and inventing an
 * order nobody agreed. A ledger with two kinds of row needs no such decision:
 * it adds up, and both sides of it are things that actually happened on a date.
 *
 * ### The cap, and what zero means
 *
 * `store_deduction_cap_cents` is the most one payslip may take. Zero — the
 * default — means "the whole outstanding balance", which is what a small tab
 * settled each cutoff actually does, and a firm that would rather spread a
 * large one sets a figure. Either way `PayrollService` floors the net at zero:
 * a tab must not produce a payslip that hands somebody nothing, because at that
 * point the deduction has stopped being a recovery and become an unpayable
 * wage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            /**
             * Is this person enrolled with each agency?
             *
             * True by default, and the default is the whole compatibility
             * story: every employee already on the roster is enrolled in all
             * three, which is what the system has been assuming since payroll
             * existed. Nobody's next payslip changes shape because of this
             * migration — the same promise the cutoff-days and pay-basis
             * columns made.
             */
            $table->boolean('sss_enrolled')->default(true)->after('daily_rate_cents');
            $table->boolean('philhealth_enrolled')->default(true)->after('sss_enrolled');
            $table->boolean('pagibig_enrolled')->default(true)->after('philhealth_enrolled');

            /**
             * The most one payslip may take off the store tab, in centavos.
             *
             * Zero means the whole outstanding balance, which is the ordinary
             * arrangement for a mini-mart tab settled at each cutoff. A figure
             * here spreads a larger balance over several payslips without
             * anybody having to remember to stop.
             */
            $table->unsignedBigInteger('store_deduction_cap_cents')
                ->default(0)
                ->after('pagibig_enrolled');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            /**
             * Which contributions this payslip was actually subject to.
             *
             * Frozen beside the figures, like `pay_basis` and `days_worked`.
             * "SSS ₱0.00" has two quite different explanations — not enrolled,
             * or nothing to contribute on — and only one of them is something
             * for the office to go and fix.
             */
            $table->boolean('sss_enrolled')->default(true)->after('sss_cents');
            $table->boolean('philhealth_enrolled')->default(true)->after('philhealth_cents');
            $table->boolean('pagibig_enrolled')->default(true)->after('pagibig_cents');

            /**
             * What came off the store tab on this payslip.
             *
             * Its own column rather than folded into `other_deductions_cents`,
             * for the reason the component totals have their own: that column
             * is the office's hand-typed escape hatch and has to survive a
             * rebuild, while this is recomputed from the ledger every time the
             * draft is rebuilt.
             */
            $table->bigInteger('store_deduction_cents')->default(0)->after('other_deductions_cents');
        });

        Schema::create('store_credits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /**
             * Cascading: a tab is not a thing that outlives the person.
             *
             * Unlike a payslip, which is a statement the firm has issued and
             * keeps. `employees` soft-deletes anyway, so the ordinary "this
             * person has left" never fires this — it only matters when a record
             * is force-deleted or a company is removed.
             */
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            /** `charge` or `payment` — see `StoreCreditKind`. */
            $table->string('kind', 20);

            /**
             * Always positive, whichever kind it is.
             *
             * A signed column would make "a negative charge" typeable and leave
             * nobody able to say what it meant, which is the same argument
             * `pay_components.kind` makes about deductions.
             */
            $table->unsignedBigInteger('amount_cents');

            /** "3 kg rice, 2 tins" — what was taken, in the storekeeper's words. */
            $table->string('description')->nullable();

            /** Which outlet. A yard may run a canteen as well as a mini-mart. */
            $table->string('outlet')->nullable();

            /**
             * The date it happened, not the date it was keyed.
             *
             * A tab is written up in a notebook and entered later, and a
             * payroll cutoff is a pair of dates — a charge typed on the 16th
             * for something taken on the 14th belongs on the first payslip.
             */
            $table->date('charged_on');

            /**
             * The payslip that took it, on a `payment` row written by payroll.
             *
             * Null on a charge, and on a repayment somebody made in cash. It
             * nulls rather than cascades on delete: a draft run being rebuilt
             * deletes its lines, and a repayment that vanished with them would
             * silently restore a balance that had been settled. Payroll only
             * writes these on **approve**, which a draft rebuild never reaches.
             */
            $table->foreignUlid('pay_run_line_id')->nullable()
                ->constrained('pay_run_lines')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The balance query: one person's rows, in date order.
            $table->index(['company_id', 'employee_id', 'charged_on']);
            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credits');

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
                'store_deduction_cents',
            ]);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
                'store_deduction_cap_cents',
            ]);
        });
    }
};
