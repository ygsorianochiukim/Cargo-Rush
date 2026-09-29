<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which trips a payslip paid for — so a trip is paid once, and late ones at all.
 *
 * ## `pay_run_line_trips`
 *
 * A per-trip payslip used to be a count of hauls delivered between the run's
 * two dates, and nothing more. So a trip recorded late — delivered on the 10th,
 * entered on the 20th, after the 1st–15th run was approved — fell into a
 * period nobody would ever build again, and was never paid. And with nothing
 * saying which hauls a run had counted, there was no safe way to go back for
 * it: any run reaching into the past could pay a trip a second time.
 *
 * One row per trip per person per line. The line is the payslip; the row says
 * this haul is on it. A draft's rows are a reservation, rewritten on every
 * rebuild; an approved or paid run's rows are the record that the trip has
 * been paid, which is what the next run reads to know what is still owed.
 *
 * ## `pay_runs.links_trips`
 *
 * True on a run built since this table existed. The runs before it counted
 * their trips and wrote nothing down, and reading the missing rows as
 * "unpaid" would hand every driver their whole history a second time. So the
 * look-back for late trips starts at the earliest run that *did* write them
 * down, and nothing older is reopened.
 *
 * ## `pay_run_lines.crew`
 *
 * Whether this payslip is a driver's or helper's — frozen at build, like
 * everything else on the line — because their wages are a cost of services and
 * an office clerk's are not, and the posting has to know which is which after
 * the person's record has moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_line_trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pay_run_line_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trip_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            // One haul, once on one payslip. Across payslips it is the service
            // that decides — a trip may sit on two drafts at once, and it is
            // approval that has to pick.
            $table->unique(['pay_run_line_id', 'trip_id']);
            $table->index(['employee_id', 'trip_id']);
        });

        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->boolean('links_trips')->default(false)->after('status');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->boolean('crew')->default(false)->after('pay_basis');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn('crew');
        });

        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->dropColumn('links_trips');
        });

        Schema::dropIfExists('pay_run_line_trips');
    }
};
