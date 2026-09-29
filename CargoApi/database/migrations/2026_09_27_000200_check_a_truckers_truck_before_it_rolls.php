<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trucker's driver runs the same pre-trip check a Cargo Rush driver does.
 *
 * An inspection used to be of a fleet `vehicles` row by a fleet `drivers` row,
 * and nothing else. A trucker's truck is a `trucker_vehicles` row and their
 * driver a `trucker_drivers` row, so the check gets a column for each — and
 * `vehicle_id` stops being required, because a check of a trucker's truck has
 * no fleet unit in it. The two kinds never share a column, the same separation
 * the drivers themselves keep.
 */
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
