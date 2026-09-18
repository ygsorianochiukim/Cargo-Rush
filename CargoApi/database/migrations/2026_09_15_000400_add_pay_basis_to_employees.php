<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each person is paid — monthly, daily, or per trip.
 *
 * Payroll could only express one of the three. Everybody else had a
 * `base_salary_cents` of zero and was silently left off every run, which was
 * the right answer to the wrong question: their money was going out through the
 * daily truck sheet and never reaching a payslip, a government contribution, or
 * the books. A driver on this system could work for a year and have no record
 * that SSS had ever been deducted from anything.
 *
 * `base_salary_cents` keeps its meaning exactly — the **monthly** basic — and
 * is only read when the basis is monthly. See `PayBasis`.
 *
 * ## The default is the old behaviour, deliberately
 *
 * Every existing employee becomes `monthly`. Somebody with a salary carries on
 * being paid it; somebody on zero carries on being left off a run, because a
 * monthly basis with no basic is exactly what `PayrollService::payable()`
 * already skips. Nobody's next payroll changes shape because of a migration —
 * the same promise the cutoff-days column made.
 *
 * ## What lands on the pay run line
 *
 * The basis is **frozen onto the payslip** along with the counts behind it:
 * how many days were worked, and how many sheet days the trip pay came from.
 * That is not decoration. A payslip reading "₱12,400" with no indication of
 * what it was 12,400 *of* is a figure the person holding it cannot check, and
 * per-trip pay is precisely the kind that gets queried. Same rule as every
 * other figure on that table: copied, not read back through anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('pay_basis', 20)
                ->default(PayBasis::Monthly->value)
                ->after('base_salary_cents');

            /**
             * The rate for one day worked. Only read on a `daily` basis.
             *
             * Its own column rather than overloading `base_salary_cents`: the
             * two are different quantities with different orders of magnitude,
             * and a firm switching somebody from monthly to daily should not
             * have a ₱30,000 monthly salary silently become a ₱30,000 day
             * rate. Keeping both means switching back is free, too.
             */
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('pay_basis');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            /** How this payslip was worked out, as it was on the day. */
            $table->string('pay_basis', 20)->default(PayBasis::Monthly->value)->after('position');

            /**
             * The workings, frozen beside the figure they produced.
             *
             * `days_worked` is what a daily rate was multiplied by;
             * `sheet_days` is how many days of the truck sheet a per-trip
             * figure was summed from. Both are zero on a monthly payslip,
             * where neither question arises.
             */
            $table->unsignedSmallInteger('days_worked')->default(0)->after('basic_cents');
            $table->unsignedSmallInteger('sheet_days')->default(0)->after('days_worked');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'days_worked', 'sheet_days']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'daily_rate_cents']);
        });
    }
};
