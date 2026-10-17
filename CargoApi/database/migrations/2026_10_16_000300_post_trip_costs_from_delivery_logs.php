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
            $table->unsignedBigInteger('posted_fuel_cents')->default(0)->after('fuel_cost_cents');
            $table->unsignedBigInteger('posted_driver_allowance_cents')->default(0)->after('posted_fuel_cents');
            $table->unsignedBigInteger('posted_helper_allowance_cents')->default(0)->after('posted_driver_allowance_cents');
            $table->string('posted_truck_id', 26)->nullable()->after('posted_helper_allowance_cents');
            $table->date('posted_on')->nullable()->after('posted_truck_id');

            $table->index(['posted_truck_id', 'posted_on']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropIndex(['posted_truck_id', 'posted_on']);
            $table->dropColumn([
                'posted_fuel_cents', 'posted_driver_allowance_cents', 'posted_helper_allowance_cents',
                'posted_truck_id', 'posted_on',
            ]);
        });
    }
};
