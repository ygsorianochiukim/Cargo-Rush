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
            $table->decimal('fuel_litres', 8, 2)->nullable()->after('receiver_name');
            $table->unsignedBigInteger('fuel_cost_cents')->nullable()->after('fuel_litres');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropColumn(['fuel_litres', 'fuel_cost_cents']);
        });
    }
};
