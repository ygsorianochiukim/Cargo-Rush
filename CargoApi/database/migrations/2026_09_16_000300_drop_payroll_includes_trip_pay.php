<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'payroll_includes_trip_pay')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_includes_trip_pay');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('payroll_includes_trip_pay')->default(false)->after('payroll_cutoff_days');
        });
    }
};
