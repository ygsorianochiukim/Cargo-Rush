<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Role as SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A position becomes a rate card: one basis, three figures, no role.
 *
 * Two changes that arrive together because they are the same decision — what a
 * position is *for*. It is the job and what the job pays. It is not the access
 * somebody gets, and it was never able to say what the job pays to somebody in
 * their first month.
 *
 * ## The rate card
 *
 * `positions` carried one amount, split across two columns by basis:
 * `base_salary_cents` for a monthly job and `daily_rate_cents` for a daily one.
 * A per-trip job carried neither, because per-trip pay held no rate at all.
 *
 * That is one figure per job, and a fleet does not price a job with one figure.
 * The same driver seat is worth less in the first month than after
 * regularisation, and an office that knows this had nowhere to write it down —
 * so it lived in somebody's head and was retyped, differently, on every hire.
 *
 * So one amount per tier, and the basis says what the amount means:
 *
 *     Position   Paid       Trainee   Probationary   Regular
 *     Driver     Per trip     1,500          1,500    1,500
 *     HR         Monthly     15,000         17,000   19,000
 *     Cashier    Monthly     12,000         14,000   16,000
 *
 * Three columns rather than five, because contractual and part-time are
 * engagements rather than stages and read the regular figure — see
 * `EmploymentType::tier()`.
 *
 * ## Existing figures go into all three
 *
 * Not into `regular` alone. The old column meant "what this job pays", full
 * stop — it was handed to every hire whatever their employment type — so
 * copying it across the three is the only backfill that leaves every existing
 * position paying exactly what it paid yesterday. Filling `regular` only would
 * quietly drop every probationary hire's pay to zero.
 *
 * ## The role goes
 *
 * `default_role_id` let a position suggest what a new account could open. It
 * conflated two questions that a small fleet answers separately: a driver who
 * also keeps the books is one job and two kinds of access, and the suggestion
 * was only ever a default the account could override anyway.
 *
 * One thing it genuinely decided has to survive it. `Position::drives()` read
 * the default role to answer *does somebody in this job need a `drivers`
 * record*, which is the same question as *do they use the handset* — every
 * driver endpoint is scoped to a `drivers` row, so an account with the driver's
 * access and no such row signs in to five empty screens. That is a fact about
 * the job, not about the access, and it becomes its own column here, backfilled
 * from the role it used to be inferred from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            /**
             * Does this job need a `drivers` record?
             *
             * Its own column now rather than a guess made from the role. False
             * by default and backfilled below, so nothing changes shape for a
             * position that was already on the roster.
             */
            $table->boolean('drives')->default(false)->after('description');

            /**
             * The rate card. What each figure means is decided by `pay_basis`
             * beside them — a month, a day, or a trip.
             *
             * Zero means "no rate set", which is what a position nobody has
             * priced must say: hiring into it leaves the figure for the office
             * to type rather than writing ₱0.00 onto somebody's contract.
             */
            $table->unsignedBigInteger('trainee_amount_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('probationary_amount_cents')->default(0)->after('trainee_amount_cents');
            $table->unsignedBigInteger('regular_amount_cents')->default(0)->after('probationary_amount_cents');
        });

        $driverRoleIds = DB::table('roles')
            ->where('key', SystemRole::Driver->value)
            ->pluck('id')
            ->all();

        if ($driverRoleIds !== []) {
            DB::table('positions')
                ->whereIn('default_role_id', $driverRoleIds)
                ->update(['drives' => true]);
        }

        // The figure that was there, whichever column it was in, across all
        // three tiers. See the note above on why it is not `regular` alone.
        foreach (DB::table('positions')->select('id', 'pay_basis', 'base_salary_cents', 'daily_rate_cents')->cursor() as $position) {
            $amount = $position->pay_basis === 'daily'
                ? (int) $position->daily_rate_cents
                : (int) $position->base_salary_cents;

            if ($amount <= 0) {
                continue;
            }

            DB::table('positions')->where('id', $position->id)->update([
                'trainee_amount_cents' => $amount,
                'probationary_amount_cents' => $amount,
                'regular_amount_cents' => $amount,
            ]);
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_role_id');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['base_salary_cents', 'daily_rate_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->foreignUlid('default_role_id')->nullable()->after('description')
                ->constrained('roles')->nullOnDelete();
            $table->unsignedBigInteger('base_salary_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('base_salary_cents');
        });

        // The regular figure is the one that goes back, because it is the one a
        // position paid a regular hire. The tiers themselves cannot be
        // preserved by a schema that has one column for them.
        foreach (DB::table('positions')->select('id', 'pay_basis', 'regular_amount_cents')->cursor() as $position) {
            DB::table('positions')->where('id', $position->id)->update(
                $position->pay_basis === 'daily'
                    ? ['daily_rate_cents' => (int) $position->regular_amount_cents]
                    : ['base_salary_cents' => (int) $position->regular_amount_cents],
            );
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['drives', 'trainee_amount_cents', 'probationary_amount_cents', 'regular_amount_cents']);
        });
    }
};
