<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a firm's pay periods actually close.
 *
 * The cutoff days themselves, which until now were written into `PayPeriod` as
 * the 15th and the end of the month, with `cargo.payroll.runs_per_month` — an
 * **environment variable** — deciding whether there were one or two of them.
 *
 * That was wrong in the way a multi-tenant setting is always wrong when it
 * lives in the environment: one value served every haulier on the install, so a
 * firm cutting off on the 10th and the 25th could not be answered at all, and
 * switching one firm to a monthly payroll switched all of them. The same
 * argument the column beside this one makes — see
 * `add_payroll_deduct_on_to_companies` — applies with more force here. A
 * government *rate* is the same for everybody and belongs in configuration. The
 * day a firm closes its books on a fortnight is its own, differs between two
 * companies in the same yard, and cannot wait for a deployment.
 *
 * ## The shape
 *
 * An ascending list of one or two day-of-month numbers, each the **last day
 * worked** in a period — which is what an office means by a cutoff.
 *
 *   `[15, 31]`  the 1st–15th and the 16th–end. The Philippine norm, and what
 *               every run built before this column existed was.
 *   `[10, 25]`  the 26th–10th and the 11th–25th. The first of those crosses a
 *               month boundary, which is why `PayrollCalendar` identifies a
 *               period by the month its *cutoff* falls in rather than by the
 *               month it starts in.
 *   `[31]`      once a month, on the last day.
 *
 * A day past the end of a short month clamps to the last one, so `31` is how a
 * firm says "the end of the month" and February takes care of itself.
 *
 * ## Why one or two and not any number
 *
 * The statutory arithmetic downstream is semi-monthly: `DeductionSchedule`
 * splits a monthly contribution across at most two payslips, and the
 * withholding table in `config/cargo.php` is the BIR's **semi-monthly** one. A
 * weekly payroll is not a longer list here — it is a different tax table — so
 * the column refuses a third cutoff rather than accepting one and quietly
 * taxing every payslip on a table built for a fortnight.
 *
 * ## Null, and why it is not backfilled
 *
 * Null means "whatever the configuration says", which is exactly what every
 * company got before this migration. Writing `[15, 31]` into every row instead
 * would pin an install running `PAYROLL_RUNS_PER_MONTH=1` to a semi-monthly
 * calendar on deploy — changing the shape of the next payroll of every firm on
 * it, silently, as a side effect of a schema change. So the default stays the
 * default until somebody chooses otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('payroll_cutoff_days')->nullable()->after('payroll_deduct_on');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_cutoff_days');
        });
    }
};
