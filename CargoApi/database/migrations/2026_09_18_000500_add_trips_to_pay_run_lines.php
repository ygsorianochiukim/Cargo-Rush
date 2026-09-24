<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many hauls this payslip paid for.
 *
 * `days_worked` and `sheet_days` already freeze the workings behind a daily
 * payslip, for the same reason every other figure on the line is frozen: a
 * payslip is a statement about a fortnight, and "₱12,400" with no indication of
 * what it was 12,400 *of* is a figure nobody holding it can check.
 *
 * Per-trip pay had no equivalent, because until the rate moved onto the
 * contract there was nothing to multiply — the amount was the sheet's own sum
 * and the workings were the sheet. Now it is a rate times a count, and the
 * count belongs on the line beside the rest of them.
 *
 * Zero for everybody else, which is what it means: a monthly payslip paid for
 * no trips, it paid for a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->unsignedInteger('trips')->default(0)->after('sheet_days');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn('trips');
        });
    }
};
