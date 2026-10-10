<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('licence_no')->unique();
            $table->date('licence_expiry');
            $table->unsignedSmallInteger('violations')->default(0);
            $table->string('status')->default('available');
            $table->unsignedInteger('trips_completed')->default(0);
            $table->decimal('on_time_rate', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('plate')->unique();
            $table->string('model');
            $table->string('registration_no');
            $table->unsignedInteger('capacity_kg');
            $table->string('status')->default('available');
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('odometer_km')->default(0);
            $table->unsignedInteger('next_service_km')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('contact');
            $table->decimal('rating', 2, 1)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
    }
};
