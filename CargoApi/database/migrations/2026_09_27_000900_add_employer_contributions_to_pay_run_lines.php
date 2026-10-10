<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->bigInteger('employer_sss_cents')->default(0)->after('pagibig_cents');
            $table->bigInteger('employer_ec_cents')->default(0)->after('employer_sss_cents');
            $table->bigInteger('employer_philhealth_cents')->default(0)->after('employer_ec_cents');
            $table->bigInteger('employer_pagibig_cents')->default(0)->after('employer_philhealth_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'employer_sss_cents',
                'employer_ec_cents',
                'employer_philhealth_cents',
                'employer_pagibig_cents',
            ]);
        });
    }
};
