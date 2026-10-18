<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->foreignUlid('payment_source_id')->nullable()->after('fuel_cost_cents')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_source_id');
        });
    }
};
