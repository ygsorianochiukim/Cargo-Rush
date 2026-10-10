<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truckers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone', 40);

            $table->string('licence_no');
            $table->date('licence_expiry')->nullable();

            $table->string('status')->default('pending');

            $table->boolean('is_online')->default(false);

            $table->unsignedSmallInteger('commission_bp')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('located_at')->nullable();

            $table->unsignedInteger('trips_completed')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'licence_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'status', 'is_online']);
        });

        Schema::create('trucker_vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trucker_id')->constrained()->cascadeOnDelete();

            $table->string('plate');
            $table->string('model');
            $table->unsignedInteger('capacity_kg');

            $table->foreignUlid('truck_category_id')->nullable()
                ->constrained('truck_categories')->nullOnDelete();

            $table->string('status')->default('available');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'plate']);
            $table->index(['trucker_id', 'status']);
        });

        Schema::create('trucker_wallet_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trucker_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind');

            $table->string('source')->nullable();

            $table->bigInteger('amount_cents');

            $table->bigInteger('gross_cents')->nullable();
            $table->unsignedSmallInteger('rate_bp')->nullable();

            $table->string('reference')->nullable();
            $table->string('note')->nullable();

            $table->date('occurred_on');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['trucker_id', 'occurred_on']);
            $table->index(['company_id', 'kind']);

            $table->unique(['trip_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trucker_wallet_entries');
        Schema::dropIfExists('trucker_vehicles');
        Schema::dropIfExists('truckers');
    }
};
