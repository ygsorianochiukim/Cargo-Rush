<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('tariff_base_cents')->nullable()->after('trucker_commission_bp');
            $table->unsignedBigInteger('tariff_per_km_cents')->nullable()->after('tariff_base_cents');
            $table->unsignedBigInteger('tariff_per_kg_cents')->nullable()->after('tariff_per_km_cents');
            $table->unsignedBigInteger('tariff_minimum_cents')->nullable()->after('tariff_per_kg_cents');

            $table->unsignedSmallInteger('billing_terms_days')->nullable()->after('tariff_minimum_cents');

            $table->unsignedSmallInteger('withholding_rate_bp')->nullable()->after('vat_rate_bp');

            $table->boolean('prices_include_vat')->nullable()->after('withholding_rate_bp');
        });

        Schema::table('truckers', function (Blueprint $table): void {
            $table->dropColumn('commission_bp');
        });
    }

    public function down(): void
    {
        Schema::table('truckers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('commission_bp')->nullable();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'tariff_base_cents',
                'tariff_per_km_cents',
                'tariff_per_kg_cents',
                'tariff_minimum_cents',
                'billing_terms_days',
                'withholding_rate_bp',
                'prices_include_vat',
            ]);
        });
    }
};
