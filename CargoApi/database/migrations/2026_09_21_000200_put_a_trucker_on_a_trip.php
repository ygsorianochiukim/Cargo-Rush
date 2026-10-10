<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trucker_commission_bp')->default(1200)->after('payroll_cutoff_days');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignUlid('trucker_id')->nullable()->after('vehicle_id')
                ->constrained()->nullOnDelete();

            $table->foreignUlid('trucker_vehicle_id')->nullable()->after('trucker_id')
                ->constrained('trucker_vehicles')->nullOnDelete();

            $table->string('booking_source')->default('cargo_rush')->after('trucker_vehicle_id');

            $table->unsignedSmallInteger('commission_bp')->nullable()->after('booking_source');
            $table->bigInteger('commission_cents')->nullable()->after('commission_bp');

            $table->index(['trucker_id', 'status']);
            $table->index(['company_id', 'booking_source']);
        });
    }

    public function down(): void
    {
        $rebuildsTables = DB::getDriverName() === 'sqlite';

        Schema::table('trips', function (Blueprint $table) use ($rebuildsTables): void {
            if ($rebuildsTables) {
                $table->dropIndex(['company_id', 'booking_source']);
                $table->dropIndex(['trucker_id', 'status']);

                $table->dropConstrainedForeignId('trucker_vehicle_id');
                $table->dropConstrainedForeignId('trucker_id');
                $table->dropColumn(['booking_source', 'commission_bp', 'commission_cents']);

                return;
            }

            $table->dropForeign(['trucker_vehicle_id']);
            $table->dropForeign(['trucker_id']);

            $table->dropIndex(['company_id', 'booking_source']);
            $table->dropIndex(['trucker_id', 'status']);

            $table->dropColumn([
                'trucker_id', 'trucker_vehicle_id', 'booking_source',
                'commission_bp', 'commission_cents',
            ]);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('trucker_commission_bp');
        });
    }
};
