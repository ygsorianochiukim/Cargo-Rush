<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->foreignUlid('vehicle_id')->nullable()->change();

            $table->foreignUlid('trucker_vehicle_id')->nullable()->after('vehicle_id')
                ->constrained('trucker_vehicles')->nullOnDelete();
            $table->foreignUlid('trucker_driver_id')->nullable()->after('driver_id')
                ->constrained('trucker_drivers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trucker_driver_id');
            $table->dropConstrainedForeignId('trucker_vehicle_id');
        });
    }
};
