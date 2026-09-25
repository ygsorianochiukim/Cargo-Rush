<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long after a cutoff the money actually goes out.
 *
 * A pay period closing on the 5th is not paid on the 5th. The office needs the
 * days between to compile the charges for the period and get the budget
 * released — ten days of billing behind it, two days of processing in front —
 * so the firm this was built for cuts off on the 5th, the 15th and the 25th and
 * releases on the 7th, the 17th and the 27th.
 *
 * `pay_runs.pay_date` has always existed and has always been typed in by hand,
 * which means it was typed differently by different people and occasionally
 * typed as the cutoff itself. This is the firm's rule, recorded once, so the
 * date arrives on the form already right.
 *
 * A column on the company rather than a line in `config/cargo.php`, for the
 * reason the cutoff days are: it is the firm's own policy and two hauliers on
 * one install will not agree on it. Null means the install default.
 *
 * It does not move a run that already exists. A draft opened on one lag keeps
 * the date it was opened with, because that date may already be on a payslip
 * somebody has been shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            /**
             * Days between a cutoff and the money leaving.
             *
             * Small, because it is days rather than weeks: a firm that pays a
             * fortnight after its cutoff has a cash-flow arrangement rather
             * than a payroll lag, and the form caps it well below what this
             * column could hold.
             *
             * Zero is a real answer — a firm paying on the cutoff itself — and
             * is why this is nullable rather than defaulted to 2 in the schema.
             * Null is "use the install default"; zero is "the same day".
             */
            $table->unsignedTinyInteger('payroll_release_lag_days')
                ->nullable()
                ->after('payroll_cutoff_days');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_release_lag_days');
        });
    }
};
