<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who the day's driver and helper pay belonged to.
 *
 * The daily truck sheet has recorded `driver_salary_cents` and
 * `helper_salary_cents` since the workbook was first modelled. What it has
 * never recorded is **whose**. The row is keyed on a truck and a date, so the
 * sheet could say a fleet paid ₱1,800 in driver wages on Tuesday and could not
 * say who to.
 *
 * That was survivable while the figure only fed Profitability, which cares what
 * a day cost and not who was in the cab. It stops being survivable the moment
 * somebody is paid *from* it: a payslip summing a driver's trips over a
 * fortnight needs to know which rows are theirs, and there was no way to ask.
 *
 * ## Both point at `drivers`, not at `employees`
 *
 * Which looks like the wrong table and is not. `drivers` is the operational
 * record — every trip, dispatch and GPS ping in the system already points at
 * it — and the helper is a `drivers` row too (`Position::needsDriverRecord()`
 * says so: a helper is a driver record without the keys). An `employees`
 * reference here would be a second way to name the same person, and the
 * ledger's neighbours all use the first. The payroll side crosses over once,
 * through `employees.driver_id`, which is the link that already exists.
 *
 * ## The backfill, and its limit
 *
 * Existing rows are filled in from the trip that opened them — `trip_id` is
 * already on the row and `trips` already names a driver and a helper. It is
 * derived from what the row implies rather than invented.
 *
 * It is also **only as good as that implication**, and the limit is worth
 * stating rather than discovering: `trip_id` names the *first* trip of that
 * day for that truck, so a day where the unit changed crew is attributed
 * wholly to whoever drove first. Rows with no trip stay null. Null means
 * unattributed and is counted toward nobody's pay — which is the safe
 * direction, because the failure is a figure somebody notices missing rather
 * than one quietly paid to the wrong person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            /**
             * Null on delete, not cascade: the day's figures are the
             * business's record of what it spent and must survive a driver
             * leaving. The row loses only the attribution.
             */
            $table->foreignUlid('driver_id')->nullable()->after('trip_id')
                ->constrained()->nullOnDelete();
            $table->foreignUlid('helper_id')->nullable()->after('driver_id')
                ->constrained('drivers')->nullOnDelete();

            // What payroll asks of this table: one person's rows in a period.
            $table->index(['company_id', 'driver_id', 'date']);
            $table->index(['company_id', 'helper_id', 'date']);
        });

        // Derived from the trip already on the row. See the note above on why
        // this is a reasonable inference and where it stops being one.
        DB::table('ledger_entries')
            ->whereNotNull('trip_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $trip = DB::table('trips')
                        ->select('driver_id', 'helper_id')
                        ->where('id', $row->trip_id)
                        ->first();

                    if ($trip === null) {
                        continue;
                    }

                    DB::table('ledger_entries')
                        ->where('id', $row->id)
                        ->update(['driver_id' => $trip->driver_id, 'helper_id' => $trip->helper_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'driver_id', 'date']);
            $table->dropIndex(['company_id', 'helper_id', 'date']);
            $table->dropConstrainedForeignId('driver_id');
            $table->dropConstrainedForeignId('helper_id');
        });
    }
};
