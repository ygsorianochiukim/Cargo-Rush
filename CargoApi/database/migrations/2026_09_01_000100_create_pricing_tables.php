<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->json('aliases')->nullable();
            $table->unsignedInteger('diesel_baseline_cents')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('pricing_brackets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('zone_id')->constrained('pricing_zones')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('min_km')->default(0);
            $table->unsignedInteger('max_km')->nullable();
            $table->unsignedBigInteger('base_cents')->default(0);
            $table->unsignedBigInteger('per_km_cents')->default(0);
            $table->unsignedBigInteger('per_kg_cents')->default(0);
            $table->unsignedBigInteger('minimum_cents')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['zone_id', 'min_km']);
        });

        Schema::create('diesel_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('effective_on')->unique();
            $table->unsignedInteger('price_per_litre_cents');
            $table->string('currency', 3)->default('PHP');
            $table->string('source')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diesel_prices');
        Schema::dropIfExists('pricing_brackets');
        Schema::dropIfExists('pricing_zones');
    }
};
