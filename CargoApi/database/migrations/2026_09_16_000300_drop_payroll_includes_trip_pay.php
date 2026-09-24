<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One switch fewer between setting somebody up and paying them.
 *
 * `payroll_includes_trip_pay` decided whether trip- and day-paid staff appeared
 * on a pay run at all. It was there to guard a real hazard: a driver's per-trip
 * money is written on the daily truck sheet, and a yard that hands that money
 * over in cash against the same sheet would pay it twice if payroll also paid
 * it.
 *
 * It cost more than it protected. Setting a driver to **per trip** and then
 * finding them on no payslip — because of a company setting on a different
 * screen, in a different module, that nobody remembered — is the kind of
 * surprise that makes a payroll module feel untrustworthy. The pay basis on the
 * person is the instruction; a second switch that can silently veto it is one
 * concept too many.
 *
 * So the basis decides, on its own: per trip means each cutoff sums that
 * person's trips and pays them. A firm that settles drivers in cash against the
 * sheet leaves those people on a monthly basis with no salary, which is exactly
 * what they were before any of this existed, and they stay off a run.
 *
 * Dropped rather than left unread. A column nothing consults is a question the
 * next person to open this table has to answer for themselves.
 */
return new class extends Migration
{
    /**
     * Guarded, because the migration that added this column has been removed.
     *
     * The column was never released, so a fresh database never grows it and
     * there is nothing here to drop — while a machine that ran the original
     * still has it and does. Checking beats leaving a migration that fails on
     * exactly one of those two, which is the kind of thing that only shows up
     * on somebody else's laptop.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'payroll_includes_trip_pay')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_includes_trip_pay');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('payroll_includes_trip_pay')->default(false)->after('payroll_cutoff_days');
        });
    }
};
