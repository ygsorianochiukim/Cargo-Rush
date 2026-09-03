<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a ledger row back to the trip that opened it.
 *
 * Trip Monitoring is one row per truck per day (DESIGN.md section 5.1), and a
 * truck can run several trips in a day, so this is deliberately not a
 * one-to-one: it names the trip whose delivery caused the row to be opened,
 * which is what makes a completed run visible on the sheet at all. Rows the
 * office enters by hand have no trip and keep a null here.
 *
 * `nullOnDelete` rather than cascade: deleting a trip must not take a day of
 * income and expenses with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignUlid('trip_id')
                ->nullable()
                ->after('truck_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trip_id');
        });
    }
};
