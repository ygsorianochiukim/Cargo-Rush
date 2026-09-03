<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a haul is worth, and who asked for it.
 *
 * Income used to reach the ledger only when somebody typed it into the daily
 * row, which meant a delivered run could earn nothing on the books until a
 * human remembered it — and two people could type two different figures for
 * the same trip. The price now travels with the trip: quoted from the tariff
 * when it is booked, so the customer is told it up front and the ledger and
 * the invoice both read it from the same column.
 *
 * `billed_at` is the guard that makes it safe. Crediting income and raising a
 * receivable happen once per trip, and this is the record of that having
 * happened — without it a second `complete` would pay the business twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            // Centavos, like every other money column (DESIGN.md section 7.1).
            $table->unsignedBigInteger('price_cents')->default(0)->after('handling');
            $table->string('currency', 3)->default('PHP')->after('price_cents');
            // Set when the delivery credited the ledger and raised the invoice.
            $table->timestamp('billed_at')->nullable()->after('eta');
            // The account that asked for it, when a customer booked it
            // themselves. Null for work the office entered.
            $table->foreignId('requested_by')->nullable()->after('billed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['price_cents', 'currency', 'billed_at']);
        });
    }
};
