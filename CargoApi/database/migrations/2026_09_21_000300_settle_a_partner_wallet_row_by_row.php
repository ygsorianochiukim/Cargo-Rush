<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which runs a payment actually covered.
 *
 * The wallet could always say what a partner was *owed* — the balance is the
 * sum of the rows, and it was right. What it could not say is **which runs have
 * been paid for**, because a payout was an amount and nothing more: ₱26,400
 * handed over against a balance of ₱26,400, with no link to the three runs that
 * made it up.
 *
 * That is fine until the first disagreement, and then it is useless. A partner
 * asking "have you paid me for CR-24823 yet?" could only be answered by adding
 * up dates and hoping. A part payment was worse: ₱10,000 against three runs
 * left every one of them in the same state as before, which is to say no state
 * at all.
 *
 * So a settlement now points at the rows it settled. One column each way:
 *
 *   `settled_by` — on a work row, the payout or remittance that cleared it.
 *   Null means outstanding, and that is the whole definition of unpaid.
 *
 *   `settled_at` — when. Denormalised from the settlement row on purpose: a
 *   statement filtered to "what was outstanding on 30 June" is a question about
 *   this column, and answering it through a join to a row that may since have
 *   been voided is how a report starts disagreeing with itself.
 *
 * ## Why not a pivot table
 *
 * A payout covers many runs, so the shape is one-to-many rather than
 * many-to-many, and a work row settled twice is not a case to model — it is a
 * bug to make impossible. A nullable foreign key says exactly that and needs no
 * table of its own.
 *
 * ## What is not settleable
 *
 * An `adjustment` moves the balance without being about a run — a damaged
 * pallet, a correction agreed by phone — so it carries no settlement state and
 * is never picked up by a payout. It still counts in the balance, which is why
 * the balance and "the sum of what is unpaid" are two different figures and the
 * service reports both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucker_wallet_entries', function (Blueprint $table): void {
            /**
             * The settlement that cleared this row.
             *
             * Points at another row in this same table — a `payout` for an
             * earning, a `remittance` for a commission. Null on delete rather
             * than cascade: voiding a payout must leave the runs it covered
             * standing and merely unpaid again, not delete the record of the
             * work.
             */
            $table->foreignUlid('settled_by')->nullable()->after('recorded_by')
                ->constrained('trucker_wallet_entries')->nullOnDelete();

            $table->timestamp('settled_at')->nullable()->after('settled_by');

            /**
             * The statement's own read: one partner's outstanding work.
             *
             * `settled_by` leads because the question is almost always "what is
             * still unpaid", and that is a null check over a narrow set.
             */
            $table->index(['trucker_id', 'settled_by']);
        });
    }

    public function down(): void
    {
        Schema::table('trucker_wallet_entries', function (Blueprint $table): void {
            $table->dropIndex(['trucker_id', 'settled_by']);
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn('settled_at');
        });
    }
};
