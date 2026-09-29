<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this firm's payroll takes SSS, PhilHealth and Pag-IBIG at all.
 *
 * The firm-wide switch above the per-person ones on `employees`. Off, no
 * payslip carries a contribution — employee or employer share — whatever the
 * person's own flags say; on, each person's flags decide, as they always have.
 * The flags are left untouched by the switch, so turning it back on returns
 * everybody to exactly the enrolment they had.
 *
 * Withholding tax is not a benefit and is not governed by this — the BIR's
 * share is owed on taxable pay either way.
 *
 * Defaults to on, which is what payroll did before the column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('payroll_benefits_enabled')->default(true)->after('payroll_deduct_on');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_benefits_enabled');
        });
    }
};
