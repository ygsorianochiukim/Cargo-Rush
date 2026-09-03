<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name the customer a day's takings came from.
 *
 * Customer history was trips and invoices only, so the money actually recorded
 * against a unit never reached it — a customer could have a month of hauling
 * behind them and show nothing. This is the join that puts it there.
 *
 * Nullable, and it has to be: the ledger's own rows are per truck per day and
 * plenty of them are not one customer's work — a day running the company's own
 * freight, or a row the office keys in from the book with no customer beside
 * it. A required column here would force somebody to invent an answer.
 *
 * `nullOnDelete`, so removing a customer does not delete a day of income and
 * expenses along with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignUlid('customer_id')
                ->nullable()
                ->after('trip_id')
                ->constrained()
                ->nullOnDelete();

            // History is read per customer, newest first.
            $table->index(['customer_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'date']);
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
