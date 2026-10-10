<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reference')->unique();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin');
            $table->string('destination');
            $table->string('cargo');
            $table->unsignedInteger('weight_kg');
            $table->unsignedSmallInteger('pieces')->default(1);
            $table->string('handling')->nullable();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('helper_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('pickup_place')->nullable();
            $table->string('dropoff_place')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('eta')->nullable();
            $table->unsignedInteger('distance_total_m')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('gps_pings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->string('location');
            $table->unsignedSmallInteger('speed_kph')->default(0);
            $table->string('heading')->default('N');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->unsignedInteger('distance_done_m')->default(0);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['trip_id', 'recorded_at']);
        });

        Schema::create('dispatch_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('dispatched_at');
            $table->string('location');
            $table->timestamp('arrived_at')->nullable();
            $table->string('status')->default('scheduled');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('delivery_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('pod_ref')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reference')->unique();
            $table->string('kind');
            $table->string('place');
            $table->timestamp('occurred_at');
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('delivery_logs');
        Schema::dropIfExists('dispatch_records');
        Schema::dropIfExists('gps_pings');
        Schema::dropIfExists('trips');
    }
};
