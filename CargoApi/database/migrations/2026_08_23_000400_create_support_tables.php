<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('icon');
            $table->string('title');
            $table->string('detail');
            $table->string('tone')->default('info');
            $table->boolean('read')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'read']);
        });

        Schema::create('inspections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->json('results');
            $table->boolean('good_to_go')->default(false);
            $table->string('notes')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();
        });

        Schema::create('maintenance_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->date('due_at');
            $table->unsignedInteger('next_service_km')->default(0);
            $table->string('status')->default('scheduled');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nav_items', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('icon');
            $table->string('route');
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('mobile')->default(false);
            $table->boolean('web')->default(true);
            $table->string('group');
            $table->string('permission')->nullable();
            $table->string('badge_source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_items');
        Schema::dropIfExists('maintenance_jobs');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('notification_items');
    }
};
