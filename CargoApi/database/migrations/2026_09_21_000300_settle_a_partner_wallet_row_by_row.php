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
            $table->foreignUlid('settled_by')->nullable()->after('recorded_by')
                ->constrained('trucker_wallet_entries')->nullOnDelete();

            $table->timestamp('settled_at')->nullable()->after('settled_by');

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
