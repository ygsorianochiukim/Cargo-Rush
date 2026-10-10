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
            $table->string('tin', 20)->nullable()->after('code');
            $table->boolean('vat_registered')->default(true)->after('tin');
            $table->unsignedSmallInteger('vat_rate_bp')->nullable()->after('vat_registered');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tin', 20)->nullable()->after('contact');
            $table->string('vat_treatment')->default('vatable')->after('tin');
            $table->boolean('withholds_tax')->default(false)->after('vat_treatment');
            $table->unsignedSmallInteger('withholding_rate_bp')->nullable()->after('withholds_tax');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('net_amount_cents')->nullable()->after('amount_cents');
            $table->unsignedBigInteger('vat_cents')->default(0)->after('net_amount_cents');
            $table->unsignedBigInteger('withholding_cents')->default(0)->after('vat_cents');

            $table->unsignedSmallInteger('vat_rate_bp')->default(0)->after('withholding_cents');
            $table->unsignedSmallInteger('withholding_rate_bp')->default(0)->after('vat_rate_bp');
            $table->string('vat_treatment')->default('vatable')->after('withholding_rate_bp');
        });

        DB::table('invoices')->whereNull('net_amount_cents')->update([
            'net_amount_cents' => DB::raw('amount_cents'),
            'vat_cents' => 0,
            'withholding_cents' => 0,
            'vat_rate_bp' => 0,
            'withholding_rate_bp' => 0,
        ]);

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('net_amount_cents')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'net_amount_cents', 'vat_cents', 'withholding_cents',
                'vat_rate_bp', 'withholding_rate_bp', 'vat_treatment',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['tin', 'vat_treatment', 'withholds_tax', 'withholding_rate_bp']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['tin', 'vat_registered', 'vat_rate_bp']);
        });
    }
};
