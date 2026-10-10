<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->unsignedInteger('min_km')->default(0)->after('code');
            $table->unsignedInteger('max_km')->nullable()->after('min_km');

            $table->index(['company_id', 'min_km']);
        });

        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->dropColumn('aliases');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->unsignedBigInteger('diesel_step_cents')->default(0)->after('minimum_cents');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->unsignedInteger('min_km')->nullable()->default(null)->change();
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->integer('fuel_surcharge_cents')->default(0)->after('fuel_adjustment_bp');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('fuel_surcharge_cents');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->dropColumn('diesel_step_cents');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->unsignedInteger('min_km')->default(0)->change();
        });

        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->json('aliases')->nullable()->after('code');
        });

        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'min_km']);
            $table->dropColumn(['min_km', 'max_km']);
        });
    }
};
