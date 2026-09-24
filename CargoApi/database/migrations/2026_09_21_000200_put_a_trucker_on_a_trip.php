<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who hauled it, and who found the work.
 *
 * A trip has always named a driver, a helper and a unit, and all three are the
 * haulier's own people and own truck. A run taken by a partner names none of
 * them: there is no employee in the cab and no company vehicle under the load.
 * These are the columns for that case, and every one of them is nullable
 * because the ordinary case — the haulier's own crew in the haulier's own truck
 * — is unchanged and writes none of them.
 *
 * ## `booking_source`, and why the money needs it
 *
 * The same partner, hauling the same pallet the same distance, is paid two
 * different ways depending on one fact: **who brokered the run.**
 *
 *   `cargo_rush` — the desk gave it to them. The haulier quoted the customer,
 *   raises the invoice and collects the money, exactly as it does for its own
 *   trucks. The partner is paid their share out of it, so the wallet is
 *   credited what is owed to them and the haulier keeps the rest.
 *
 *   `direct` — the partner took it off the open board themselves. The money is
 *   between them and the customer and never passes through the haulier's bank;
 *   what the haulier is owed is its cut of a run it did not collect, so the
 *   wallet is charged rather than credited.
 *
 * Both are the same percentage. What differs is the direction, and a column is
 * the only honest way to record which applied — the two produce different rows
 * in the wallet, and a year later somebody will ask why.
 *
 * It is set at the moment a partner is attached to the run, not at the moment
 * the customer asked: a request that sat on the board and was then handed out
 * by the desk is `cargo_rush`, and the identical request grabbed by a partner
 * first is `direct`. That is the fact being recorded — how the work reached the
 * person who hauled it.
 *
 * Every existing row, and every future run by the haulier's own crew, is
 * `cargo_rush`. That is not a placeholder: work the office booked and billed
 * *is* the haulier's own, and the column reads true for all of it.
 *
 * ## The frozen rate
 *
 * `commission_bp` and `commission_cents` record what was actually taken, on the
 * trip, at the moment it was delivered. The rate lives on the company and may
 * be overridden per partner, and both can be edited — so a trip that did not
 * carry its own copy would silently restate every past run's split the first
 * time somebody renegotiated. The invoice module freezes its tax rates for
 * exactly this reason, and this is the same rule applied to the same kind of
 * number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            /**
             * The haulier's standing cut of a partner's run, in basis points.
             *
             * 1200 — twelve per cent — is the rate this was built for, and it
             * is a default rather than a constant: it is the commercial term
             * between a fleet and the owner-operators hauling for it, and the
             * one number in this feature most likely to be argued about. A
             * partner may be put on their own rate; this is what applies when
             * they are not.
             */
            $table->unsignedSmallInteger('trucker_commission_bp')->default(1200)->after('payroll_cutoff_days');
        });

        Schema::table('trips', function (Blueprint $table): void {
            /**
             * The partner hauling this, if it is not the haulier's own crew.
             *
             * Null on delete rather than cascade, like `driver_id`: removing a
             * partner from the roster must not delete the history of what they
             * carried, which the delivery logs and the customer's invoices both
             * still refer to.
             */
            $table->foreignUlid('trucker_id')->nullable()->after('vehicle_id')
                ->constrained()->nullOnDelete();

            /** Their unit. Beside `vehicle_id`, never instead of it — a run has one or the other. */
            $table->foreignUlid('trucker_vehicle_id')->nullable()->after('trucker_id')
                ->constrained('trucker_vehicles')->nullOnDelete();

            /** `cargo_rush` or `direct` — see the class docblock. */
            $table->string('booking_source')->default('cargo_rush')->after('trucker_vehicle_id');

            /** What was actually taken, frozen at delivery. Null until then. */
            $table->unsignedSmallInteger('commission_bp')->nullable()->after('booking_source');
            $table->bigInteger('commission_cents')->nullable()->after('commission_bp');

            // The partner's own queue, which is the read their handset makes
            // every time it opens.
            $table->index(['trucker_id', 'status']);
            // The audit read: every run that came in one way, over a period.
            $table->index(['company_id', 'booking_source']);
        });
    }

    public function down(): void
    {
        /**
         * The two drivers want opposite orders here, and each refuses the
         * other's outright. Both were arrived at by running the rollback, not
         * by reading the manual, so neither branch should be tidied into the
         * other without doing the same.
         *
         * **MySQL** enforces a foreign key using an index on the referencing
         * column, and it picked `trips_trucker_id_status_index` for
         * `trucker_id`. Dropping that index while the constraint exists fails
         * with "needed in a foreign key constraint", so the constraint comes
         * off first and the index is then ordinary.
         *
         * **SQLite** drops a key *with* its column. Dropping the constraint
         * separately is not a statement it has; going the MySQL way leaves the
         * table carrying a foreign key definition naming a column that is
         * being removed in the same breath, and the rebuild fails with
         * "unknown column in foreign key definition".
         */
        $rebuildsTables = DB::getDriverName() === 'sqlite';

        Schema::table('trips', function (Blueprint $table) use ($rebuildsTables): void {
            if ($rebuildsTables) {
                $table->dropIndex(['company_id', 'booking_source']);
                $table->dropIndex(['trucker_id', 'status']);

                // Key and column together, which is the only way SQLite has.
                $table->dropConstrainedForeignId('trucker_vehicle_id');
                $table->dropConstrainedForeignId('trucker_id');
                $table->dropColumn(['booking_source', 'commission_bp', 'commission_cents']);

                return;
            }

            $table->dropForeign(['trucker_vehicle_id']);
            $table->dropForeign(['trucker_id']);

            $table->dropIndex(['company_id', 'booking_source']);
            $table->dropIndex(['trucker_id', 'status']);

            $table->dropColumn([
                'trucker_id', 'trucker_vehicle_id', 'booking_source',
                'commission_bp', 'commission_cents',
            ]);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('trucker_commission_bp');
        });
    }
};
