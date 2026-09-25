<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money on its way is not money that arrived.
 *
 * A payout used to be instantaneous by construction: the office recorded it,
 * the row went in, the balance dropped, and the run read "paid". That is true
 * of cash handed across a desk and false of everything else. A bank transfer
 * takes a day, sometimes fails, and in the meantime the wallet told a partner
 * they had been paid ₱26,400 that was not in their account.
 *
 * Two columns fix it, and one rule follows from them.
 *
 * ## `status`
 *
 * From the shared vocabulary, and only two of it are used: `pending` for a
 * payment the office has started, `paid` for one that has landed.
 *
 * **Only a settlement is ever `pending`.** An earning is not a payment in
 * flight — it is a fact about work that was done — so it is `paid` from the
 * moment it is written, as is a commission and an adjustment. Defaulting the
 * column to `paid` therefore backfills every existing row correctly rather
 * than approximately: nothing written before this migration was ever in
 * flight, because there was no such state to be in.
 *
 * ## The rule
 *
 * **The balance counts only what has landed.** A `pending` payout is money
 * committed but not arrived, so the partner is still owed it: their wallet
 * reads ₱26,400 owed with ₱26,400 on the way, and drops to nought when the
 * office marks it paid.
 *
 * That is the honest reading and it is also the safe one. A transfer that
 * bounces has paid nobody, and a balance that had already fallen to nought
 * would have to be corrected by hand — by which point the partner has been
 * told twice that they were paid.
 *
 * It does not open a double-payment hole: a run is off the payable list the
 * moment it is *settled*, which `settled_by` records independently of whether
 * the money has cleared.
 *
 * ## `method`
 *
 * Cash, cheque, bank transfer, online. Free-ish text rather than an enum, for
 * the same reason `payments.method` is — the list is a business's own and
 * grows — and defaulted to a transfer, which is what almost every payout to a
 * contractor actually is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucker_wallet_entries', function (Blueprint $table): void {
            /**
             * `paid` or `pending`. Only a payout or a remittance is ever the
             * latter — see the class docblock for why the default backfills
             * correctly.
             */
            $table->string('status')->default('paid')->after('kind');

            /** How it was sent. Null on the rows that are not payments. */
            $table->string('method')->nullable()->after('status');

            /**
             * The balance's own read: one partner's rows that have landed.
             *
             * `status` leads because the balance excludes in-flight payments
             * and that is now the most common filter on this table.
             */
            $table->index(['trucker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('trucker_wallet_entries', function (Blueprint $table): void {
            $table->dropIndex(['trucker_id', 'status']);
            $table->dropColumn(['status', 'method']);
        });
    }
};
