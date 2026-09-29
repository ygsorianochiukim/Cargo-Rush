<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A trucker's truck is photographed and checked before it hauls.
 *
 * Six photographs — the four sides, the plate, and the engine bay if the
 * trucker has one to hand — and the office's verdict on them. Paths only; the
 * URL is derived on read, as it is for staff photographs and proof of delivery.
 *
 * Trucks already on the books are marked verified. They were added by truckers
 * the office had approved, before there was anything to check them against,
 * and taking every one of them off the road at once would empty the job board
 * of work that is already running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucker_vehicles', function (Blueprint $table): void {
            $table->string('photo_front_path')->nullable()->after('status');
            $table->string('photo_left_path')->nullable()->after('photo_front_path');
            $table->string('photo_right_path')->nullable()->after('photo_left_path');
            $table->string('photo_back_path')->nullable()->after('photo_right_path');
            $table->string('photo_plate_path')->nullable()->after('photo_back_path');
            $table->string('photo_engine_path')->nullable()->after('photo_plate_path');

            $table->string('verification')->default('pending')->after('photo_engine_path')->index();
            $table->timestamp('verified_at')->nullable()->after('verification');
            $table->foreignId('verified_by')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable()->after('verified_by');
        });

        DB::table('trucker_vehicles')->update(['verification' => 'verified', 'verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('trucker_vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'photo_front_path', 'photo_left_path', 'photo_right_path', 'photo_back_path',
                'photo_plate_path', 'photo_engine_path',
                'verification', 'verified_at', 'rejection_reason',
            ]);
        });
    }
};
