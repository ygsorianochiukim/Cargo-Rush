<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucker_wallet_entries', function (Blueprint $table): void {
            $table->date('period_start')->nullable()->after('occurred_on');
            $table->date('period_end')->nullable()->after('period_start');
            $table->index(['trucker_id', 'period_start', 'period_end'], 'wallet_entries_cutoff_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trucker_wallet_entries', function (Blueprint $table): void {
            $table->dropIndex('wallet_entries_cutoff_idx');
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
