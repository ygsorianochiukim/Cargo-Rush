<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What one person is paid, on its own row, with a date on it.
 *
 * Pay lived in three columns on `employees` — `pay_basis`, `base_salary_cents`,
 * `daily_rate_cents` — which is one figure that gets overwritten. The record of
 * what somebody was on before a rise was the rise itself destroying it.
 *
 * That is fine right up to the first question anybody asks about pay: what was
 * she on last year, when did this go up, and why is the payslip from March
 * different from the one from May. A column cannot answer any of them.
 *
 * ## A raise appends; it never overwrites
 *
 * One row per agreement, carrying the basis, the amount, and the date it starts
 * on. Raising one person is one new row, and it touches nothing else — not the
 * position, not the people beside them on the same job, and not a single
 * payslip already issued.
 *
 * Which is the half of this the rate card cannot do. A position says what a job
 * pays a **new hire**; a contract says what this person is on. Somebody
 * negotiated up keeps their figure when the position's is raised, and raising
 * the position does not restate what anybody is owed — including on a run
 * somebody is halfway through checking.
 *
 * ## Payroll reads the row in force
 *
 * The latest row whose `effective_from` has arrived. Not the newest row: a rise
 * dated the first of next month can be written today and takes effect on its
 * own, which is how an office actually works, and how a run rebuilt for last
 * fortnight keeps paying last fortnight's figure.
 *
 * Ties break on `created_at` — two rows dated the same day means somebody
 * corrected the first, and the correction is the one that counts.
 *
 * ## The tier is copied onto the row
 *
 * Not read back off the employee. `tier` says which column of the rate card the
 * figure came from at the moment it was agreed, so a contract still explains
 * itself after the person is regularised, and the next contract is the one that
 * says `regular`.
 *
 * ## One thing needs the office's attention after this runs
 *
 * Per-trip staff had no rate anywhere in this system: their pay was the
 * `driver_salary` column on the daily truck sheet, summed. There is no figure
 * to migrate, and inventing one by averaging the sheet would be inventing
 * money. So a per-trip contract is created at zero, and until somebody sets the
 * trip rate those payslips build at zero with `PayRunLine::zeroExplanation()`
 * saying exactly that. Monthly and daily staff carry their existing figure
 * across untouched and nothing about their pay changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            /** Monthly, daily or per trip — what the amount beside it means. */
            $table->string('pay_basis', 20)->default(PayBasis::Monthly->value);

            /**
             * Which column of the position's rate card this came from.
             *
             * Copied, not looked up: it is what was true when the figure was
             * agreed, and it is what makes a row from two years ago still
             * explain itself.
             */
            $table->string('tier', 20)->default(EmploymentType::Regular->value);

            /** The figure. Per month, per day, or per trip — see `pay_basis`. */
            $table->unsignedBigInteger('amount_cents')->default(0);

            /**
             * The day this agreement starts paying.
             *
             * A date rather than a timestamp, because payroll asks about
             * periods and nobody agrees a salary at 14:32. Future dates are
             * allowed and are the point: next month's rise is written today.
             */
            $table->date('effective_from');

            /**
             * Why, in the office's own words — "Hired into Driver",
             * "Regularised", "Annual increase".
             *
             * Optional, and worth having: a column of figures with no reasons
             * is one somebody has to reconstruct from memory the first time it
             * is queried.
             */
            $table->string('reason')->nullable();

            /**
             * Who wrote it down.
             *
             * `foreignId`, not `foreignUlid`: `users` is the one table in this
             * schema still on an auto-incrementing key, and MySQL refuses a
             * foreign key between a char(26) and a bigint outright. The payroll
             * tables reference it the same way for the same reason.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The lookup payroll makes for every person on every run.
            $table->index(['employee_id', 'effective_from']);
        });

        $this->backfill();

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'base_salary_cents', 'daily_rate_cents']);
        });
    }

    /**
     * One opening contract per employee, from what their record already said.
     *
     * Dated from `hired_on` where the roster knows it, because that is when the
     * agreement actually started — not the day this migration ran. A record
     * with no hire date falls back to when it was created, and then to today,
     * so every employee ends up with exactly one row and payroll finds a figure
     * for everybody it found one for yesterday.
     */
    private function backfill(): void
    {
        $now = Carbon::now();

        $employees = DB::table('employees')->select(
            'id', 'company_id', 'employment_type', 'pay_basis',
            'base_salary_cents', 'daily_rate_cents', 'hired_on', 'created_at',
        )->cursor();

        foreach ($employees as $employee) {
            $basis = (string) ($employee->pay_basis ?? PayBasis::Monthly->value);

            $amount = match ($basis) {
                PayBasis::Daily->value => (int) $employee->daily_rate_cents,
                // Per-trip carried no rate to migrate. See the note above.
                PayBasis::PerTrip->value => 0,
                default => (int) $employee->base_salary_cents,
            };

            $type = EmploymentType::tryFrom((string) ($employee->employment_type ?? ''))
                ?? EmploymentType::Regular;

            DB::table('contracts')->insert([
                'id' => (string) Str::ulid(),
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'pay_basis' => $basis,
                'tier' => $type->tier()->value,
                'amount_cents' => $amount,
                'effective_from' => Carbon::parse(
                    $employee->hired_on ?? $employee->created_at ?? $now,
                )->toDateString(),
                'reason' => 'Opening contract, carried over from the employee record.',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('pay_basis', 20)->default(PayBasis::Monthly->value)->after('employment_type');
            $table->unsignedBigInteger('base_salary_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('base_salary_cents');
        });

        // The figure in force today goes back onto the record. The history does
        // not survive, because the shape it is going back into cannot hold it.
        foreach (DB::table('employees')->select('id')->cursor() as $employee) {
            $contract = DB::table('contracts')
                ->where('employee_id', $employee->id)
                ->whereNull('deleted_at')
                ->whereDate('effective_from', '<=', Carbon::now()->toDateString())
                ->orderByDesc('effective_from')
                ->orderByDesc('created_at')
                ->first();

            if ($contract === null) {
                continue;
            }

            $daily = $contract->pay_basis === PayBasis::Daily->value;

            DB::table('employees')->where('id', $employee->id)->update([
                'pay_basis' => $contract->pay_basis,
                'base_salary_cents' => $daily ? 0 : (int) $contract->amount_cents,
                'daily_rate_cents' => $daily ? (int) $contract->amount_cents : 0,
            ]);
        }

        Schema::dropIfExists('contracts');
    }
};
