<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->foreignUlid('truck_category_id')->nullable()->after('zone_id')
                ->constrained('truck_categories')->nullOnDelete();

            $table->index(['company_id', 'truck_category_id']);
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->dropForeign(['zone_id']);
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->ulid('zone_id')->nullable()->change();
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->foreign('zone_id')->references('id')->on('pricing_zones')->cascadeOnDelete();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignUlid('truck_category_id')->nullable()->after('company_id')
                ->constrained('truck_categories')->nullOnDelete();
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignUlid('truck_category_id')->nullable()->after('vehicle_id')
                ->constrained('truck_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('truck_category_id');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('truck_category_id');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('truck_category_id');
        });

        Schema::dropIfExists('truck_categories');
    }
};
