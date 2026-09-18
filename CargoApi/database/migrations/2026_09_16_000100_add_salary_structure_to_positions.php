<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a job pays, kept on the job rather than typed per person.
 *
 * `positions` already holds what somebody **is** — Driver, Dispatcher, Treasury
 * Officer — and the access that normally goes with it. What it could not say is
 * what the job pays, so every hire meant somebody remembering the rate for a
 * driver and typing it again. Which is how two drivers end up on different
 * money for no reason anybody can reconstruct a year later.
 *
 * So a position carries a pay basis and the rate that goes with it, and hiring
 * somebody into it **copies** both onto their employee record.
 *
 * ## Copied, not referenced, and that is the whole design
 *
 * The employee keeps their own `pay_basis`, `base_salary_cents` and
 * `daily_rate_cents`. Payroll reads those and never looks here.
 *
 * A live reference would have been fewer columns and a serious bug: raising the
 * driver rate for next year's hires would silently restate what every existing
 * driver is owed, including on the run somebody is halfway through checking.
 * That is the same reasoning `employees.position` already follows — the job
 * *title* is copied too, so a position renamed later leaves old records saying
 * what that person was actually called at the time.
 *
 * What a position holds is therefore a **default at the moment of hire**, not a
 * salary. Somebody negotiated up keeps their figure, and the office changes one
 * person's pay by editing that person.
 *
 * ## Per company, already
 *
 * Nothing here makes positions per-tenant, because they already are: the
 * tenancy migration gave `positions` a non-null `company_id` and rescoped its
 * `key` uniqueness to `(company_id, key)`, and `CompanyProvisioner` seeds each
 * new company its own set. Two firms can both have a "Driver" on entirely
 * different money, and neither can see the other's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            /**
             * How this job is paid — monthly, daily, or per trip.
             *
             * Monthly by default, which is what every position already on the
             * roster is treated as, so nobody's next hire changes shape because
             * of a migration.
             */
            $table->string('pay_basis', 20)
                ->default(PayBasis::Monthly->value)
                ->after('default_role_id');

            /**
             * The rates. Which one is read depends on the basis, and a
             * per-trip job reads neither.
             *
             * Both default to zero, meaning "no rate set" — a position with no
             * rate copies nothing and the office types the figure on the hire,
             * exactly as they did before this existed. That is the honest
             * default for every position already on the roster: the system has
             * never been told what a Dispatcher earns and must not invent one.
             */
            $table->unsignedBigInteger('base_salary_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('base_salary_cents');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'base_salary_cents', 'daily_rate_cents']);
        });
    }
};
