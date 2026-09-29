<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employer's share of SSS, EC, PhilHealth and Pag-IBIG, per payslip.
 *
 * Payroll worked out what the government takes **from** a payslip and nothing
 * the firm pays **on top of** it — so the fleet's cost of employing somebody on
 * ₱30,000 read ₱30,000, when the remittance slip said ₱3,980 more. That
 * money is a real cost and a real debt to the agencies, and it was on no
 * report and in no journal entry.
 *
 * One column per agency, beside the employee's own, because each is remitted
 * on its own form — and EC apart from SSS because it is a flat amount on the
 * salary credit rather than a rate, and the R-5 prints it on its own line.
 *
 * Frozen on the line like every other figure there. None of it is deducted:
 * gross, deductions and net are untouched.
 *
 * Zero on every run built before this, and deliberately not backfilled. The
 * paid ones are already in the journal without it; filling the columns in
 * would make Finance count a cost the books never posted.
 */
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
