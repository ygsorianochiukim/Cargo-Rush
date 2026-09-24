<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary structure: the things a firm pays and deducts every period.
 *
 * Payroll could already work out a basic and the four statutory deductions.
 * Everything else — a rice allowance, a COLA, a uniform deduction, the
 * repayment on a cash advance — had one home: `adjustments_cents` and
 * `allowance_cents`, typed onto a payslip by hand, **every cutoff, for every
 * employee, forever**. That is the part of a payroll module an office actually
 * complains about, and it is where the typos are.
 *
 * Three tables, and the shape of them is the same argument the rest of payroll
 * makes about documents and records.
 *
 * ## `pay_components` — the firm's catalogue
 *
 * What this company pays and deducts, as a list it keeps. A name, whether it is
 * an earning or a deduction, how the amount is arrived at (a fixed peso figure
 * or a percentage of the basic), and which payslip of the month it lands on.
 * Per company, because "COLA" means a different amount in two firms in the same
 * yard, and nothing about it is the government's.
 *
 * ## `employee_pay_components` — who gets what
 *
 * The assignment, with an amount that may override the catalogue's and a date
 * range it applies over. The dates are the important half: a rise, an allowance
 * that starts in March, a loan that finishes repaying in August. Without them a
 * firm would have to remember to unassign things on the right day, which is to
 * say it would not.
 *
 * ## `pay_run_line_components` — what was actually paid
 *
 * The **frozen copy**, one row per component per payslip, carrying the name and
 * the amount as they were. Same rule as every other figure on a pay run line
 * and for the same reason: a payslip is a statement about a fortnight and has
 * to keep saying what it said after the catalogue is edited, the assignment
 * ends, or the component is deleted outright. A payslip that itemised itself by
 * reading today's catalogue would restate every payslip ever issued the first
 * time somebody renamed an allowance.
 *
 * ## Why the totals get their own columns on the line
 *
 * `allowance_cents` and `other_deductions_cents` already exist and stay exactly
 * as they were — the hand-typed escape hatch, which is still the right answer
 * for a one-off. The components total into `component_earnings_cents` and
 * `component_deductions_cents` beside them rather than into them.
 *
 * Sharing a column would have been fewer columns and a real bug: rebuilding a
 * run recomputes the components, and if they landed in `allowance_cents` a
 * rebuild would silently wipe the allowance somebody typed by hand — or, worse,
 * double it. Kept apart, the manual figure survives a rebuild, and the
 * itemised rows always add up to the column beside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /** "Rice allowance", "COLA", "SSS loan". The office's own words. */
            $table->string('name');

            /**
             * `earning` or `deduction`.
             *
             * Which side of the payslip it lands on, and it is not derivable
             * from the sign of the amount: a deduction is stored as a positive
             * number that comes off, exactly as the statutory columns are.
             * Signed amounts would make "a negative deduction" a thing somebody
             * could type, and nobody could say what it meant.
             */
            $table->string('kind');

            /**
             * `fixed` or `percent_of_basic`.
             *
             * A percentage is worth having rather than making the office do the
             * arithmetic: an allowance set at 10% of the basic follows a rise
             * on its own, and a firm that has to recompute it by hand on every
             * promotion will eventually not.
             */
            $table->string('basis')->default('fixed');

            /** Centavos, for a `fixed` component. Ignored by a percentage one. */
            $table->bigInteger('amount_cents')->default(0);
            /** Basis points (450 = 4.5%), like every other rate in this system. */
            $table->unsignedInteger('rate_bp')->default(0);

            /**
             * Which payslip of the month carries it — see `PayComponentSchedule`.
             *
             * `each_run` for an amount that applies in full to every payslip;
             * the other three treat it as a **monthly** figure and split or load
             * it exactly as `DeductionSchedule` does the statutory ones. A firm
             * that says "₱2,000 rice allowance" usually means a month of it, and
             * one that says "₱500 a payslip" means a payslip — both are ordinary
             * and neither can be guessed from the number.
             */
            $table->string('schedule')->default('each_run');

            /**
             * Does this earning go into the tax base?
             *
             * Default false, and that is the honest default rather than a
             * cautious one: de minimis benefits — rice, uniform, medical
             * allowance — are non-taxable up to the BIR's ceilings, which is
             * most of what a fleet actually pays on top of a basic. A firm
             * paying a taxable allowance ticks this and the withholding follows.
             *
             * Meaningless on a deduction, and ignored there rather than
             * forbidden: a checkbox that vanishes when you change a dropdown is
             * worse than one that stops mattering.
             */
            $table->boolean('taxable')->default(false);

            $table->string('status')->default('active');
            /** Where it sits on a payslip. The office's order, not the id's. */
            $table->unsignedInteger('position')->default(0);
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One "Rice allowance" per company. Two would make a payslip
            // ambiguous and a report wrong.
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('employee_pay_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            /**
             * Cascading, unlike the pay run line's reference to the same table.
             *
             * An assignment is a live instruction about future payslips, so it
             * has no meaning once the component is gone. A *payslip* that
             * already carried it keeps its frozen copy — that is the row in
             * `pay_run_line_components`, and its foreign key nulls rather than
             * cascades for exactly this reason.
             */
            $table->foreignUlid('pay_component_id')->constrained()->cascadeOnDelete();

            /**
             * This person's amount, where it differs from the catalogue's.
             *
             * Null means "whatever the component says", which is the common
             * case and the one worth keeping cheap: a firm that raises its rice
             * allowance edits one row rather than ninety. An override is for
             * the person whose figure is genuinely their own.
             */
            $table->bigInteger('amount_cents')->nullable();
            $table->unsignedInteger('rate_bp')->nullable();

            /**
             * When it applies from, and until.
             *
             * A component counts on a run when its window overlaps the period.
             * `effective_to` null means open-ended, which is most assignments;
             * a date there is how a loan stops being deducted on the payslip
             * after the last one rather than on the day somebody remembers.
             */
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('status')->default('active');
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'pay_component_id']);
        });

        Schema::create('pay_run_line_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            // Cascading: an itemised line without its payslip is not anything.
            $table->foreignUlid('pay_run_line_id')->constrained()->cascadeOnDelete();

            /**
             * The catalogue row this came from, for a link back — and nothing
             * is read through it.
             *
             * Nulls on delete rather than restricting, which is the difference
             * between this and the employee reference on `pay_run_lines`. A
             * firm that stops paying a COLA should be able to remove the
             * component; the payslips that carried it keep their own copy of
             * the name and the amount and go on saying what they said.
             *
             * Note what actually protects the payslip, though: it is the copied
             * columns, not this key. `PayComponent` soft-deletes like
             * everything else here, so the ordinary delete never fires the
             * constraint at all — it only matters if a row is force-deleted or
             * a company is removed. A payslip that relied on the key to null
             * would be a payslip that went blank on `forceDelete` and stayed
             * wrong on a soft one.
             */
            $table->foreignUlid('pay_component_id')->nullable()->constrained()->nullOnDelete();

            // Frozen, all of it. See the note at the top of this file.
            $table->string('name');
            $table->string('kind');
            $table->boolean('taxable')->default(false);
            $table->bigInteger('amount_cents')->default(0);

            $table->timestamps();

            $table->index('pay_run_line_id');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            /**
             * What the components added and took off this payslip.
             *
             * Beside `allowance_cents` and `other_deductions_cents`, never
             * inside them — the hand-typed figures are somebody's correction to
             * this run and must survive a rebuild that recomputes the
             * components. See the note at the top of this file.
             *
             * Each is the sum of the `pay_run_line_components` rows of that
             * kind, and `PayRunLine::isConsistent()` says so.
             */
            $table->bigInteger('component_earnings_cents')->default(0)->after('adjustment_note');
            $table->bigInteger('component_deductions_cents')->default(0)->after('other_deductions_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn(['component_earnings_cents', 'component_deductions_cents']);
        });

        Schema::dropIfExists('pay_run_line_components');
        Schema::dropIfExists('employee_pay_components');
        Schema::dropIfExists('pay_components');
    }
};
