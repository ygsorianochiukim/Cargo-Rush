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
            $table->string('status')->default('paid')->after('kind');

            $table->string('method')->nullable()->after('status');

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
