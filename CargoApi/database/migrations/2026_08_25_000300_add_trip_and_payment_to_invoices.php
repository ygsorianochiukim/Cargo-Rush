<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link between a delivered haul and the receivable it raised, and the
 * moment the money actually arrived.
 *
 * Billing could show what was owed but never which run it was owed for, so
 * reconciling an invoice against a delivery was a manual match on dates and
 * amounts. `trip_id` is that match, made once, by the code that raises the
 * document — and it is also what stops a second `complete` on the same trip
 * from raising a duplicate invoice.
 *
 * `paid_at` is when it was settled. The status says *that* it was; a payment
 * history needs to know *when*, and deriving it from `updated_at` would move
 * every time anything else on the row was corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUlid('trip_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trip_id');
            $table->dropColumn('paid_at');
        });
    }
};
