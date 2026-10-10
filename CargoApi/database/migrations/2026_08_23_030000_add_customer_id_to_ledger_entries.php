<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
