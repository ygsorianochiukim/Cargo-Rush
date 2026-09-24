<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking for the pay cutoff to be moved, when you cannot move it yourself.
 *
 * The cutoff lives on the company and is written through `PATCH /company`,
 * which needs `company.manage` — a permission only the administrator holds out
 * of the box. That is deliberate: the cutoff reshapes every future pay period,
 * and it should not be one more field on the screen of whoever happens to be
 * running this fortnight's payroll.
 *
 * But the person running this fortnight's payroll is exactly who *notices*.
 * They are the one who sees that the fortnight the system thinks it is paying
 * is not the fortnight the office actually works. Before this table their only
 * route was to find the administrator and describe it out loud, which is how a
 * setting ends up changed from memory, on the wrong day, to the wrong days.
 *
 * So: they file the change they want, with the reason. An administrator sees
 * it, and **approving it applies it**. That last part is the point of the table
 * rather than an afterthought — an approval that only said "yes, go and type
 * it in" would put the retyping, and the chance of a typo, back exactly where
 * this is trying to remove it from.
 *
 * ## What is stored, and why the decision half is nullable
 *
 * The request is the top half and is written once. The decision is the bottom
 * half and is null until somebody makes one — which is the shape of every
 * approval in this system, and it means a pending request is identifiable
 * without a status column being the only thing keeping the two halves honest.
 * The status column is there as well, because "pending" is a thing screens
 * filter on and deriving it from four nulls is how one screen gets it wrong.
 *
 * ## `previous_cutoff_days`
 *
 * What the firm was on at the moment of approval, copied here. Not derivable
 * afterwards — the company row holds only what it is on *now* — and without it
 * the log says a change was approved without saying what changed. The same
 * reasoning that freezes a payslip's figures onto its line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_cutoff_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /**
             * The days being asked for — one or two, ascending, as the column
             * on `companies` holds them.
             *
             * Validated for shape when filed, and **again** when approved. The
             * shape rules cannot change underneath a request, but the rule
             * about an open draft run can, so approval is not a rubber stamp
             * on something already checked. See `PayrollCutoffDays`.
             */
            $table->json('cutoff_days');

            /**
             * The deduction schedule, where the request covers that too.
             *
             * Null means "leave it alone", which is most requests: the two
             * settings are on one card because they are one conversation, but
             * an office asking to move its cutoff usually has no opinion about
             * which payslip the contributions land on.
             */
            $table->string('payroll_deduct_on', 10)->nullable();

            /**
             * Why.
             *
             * Required, and the only free-text field here that is. An
             * administrator deciding this is being asked to change the shape of
             * every future pay period on the strength of somebody else's
             * judgement; "because the yard changed its week" is the difference
             * between a decision and a rubber stamp.
             */
            $table->string('reason', 255);

            /**
             * pending → approved | declined | withdrawn.
             *
             * Its own vocabulary rather than the shared status list, for the
             * reason `applicants.stage` has one: "declined" is not a status any
             * other module has, and overloading `cancelled` for it would lose
             * the difference between a request somebody turned down and one the
             * asker took back.
             */
            $table->string('status')->default('pending');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            /** The administrator's own words, chiefly for a decline. */
            $table->string('decision_note', 255)->nullable();

            /** What the firm was on when this was approved. See the note above. */
            $table->json('previous_cutoff_days')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_cutoff_requests');
    }
};
