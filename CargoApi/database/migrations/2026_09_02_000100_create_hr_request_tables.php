<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time off, and time cut short.
 *
 * The two things an HR module is asked for on its first day. Both are the same
 * shape — somebody asks, somebody decides — and they are still two tables
 * rather than one with a `kind` column, because what they measure is different:
 * leave is counted in days against an entitlement, undertime in hours against a
 * shift. Folding them together would mean every roll-up carrying a branch, and
 * a `days` column that means hours half the time.
 *
 * What both carry is the decision, not just its outcome: who decided, when, and
 * what they said. "Was my leave approved?" and "who approved it?" are the two
 * questions this module exists to answer, and a bare status answers only the
 * first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            // vacation / sick / emergency / unpaid / maternity / paternity /
            // bereavement — a closed list, because leave types are set by
            // policy rather than invented per request.
            $table->string('type');
            $table->date('starts_on');
            $table->date('ends_on');
            /**
             * Derived from the dates on write, never typed.
             *
             * One decimal because a half day is a real request. Storing it at
             * all — rather than computing it on read — is what lets a year's
             * leave be summed without reopening every row, and deriving it on
             * write is what stops the figure disagreeing with its own dates.
             */
            $table->decimal('days', 4, 1)->default(0);
            $table->string('reason');
            // pending / approved / rejected / cancelled
            $table->string('status')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The two queries this table serves: one person's history, and
            // everything still waiting on a decision.
            $table->index(['employee_id', 'starts_on']);
            $table->index('status');
        });

        Schema::create('undertime_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Times of day, not timestamps: this is a slice out of one shift,
            // and a timestamp would invite a request that spans midnight.
            $table->time('from_time');
            $table->time('to_time');
            // Derived from the two times on write, for the same reason `days` is.
            $table->decimal('hours', 5, 2)->default(0);
            $table->string('reason');
            $table->string('status')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undertime_requests');
        Schema::dropIfExists('leave_requests');
    }
};
