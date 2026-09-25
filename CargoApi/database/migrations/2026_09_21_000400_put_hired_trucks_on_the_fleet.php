<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Not every truck on the fleet belongs to it.
 *
 * `vehicles` has meant "a truck this company owns" since it was written, and
 * the business has outgrown that in three directions at once: units hired for a
 * monthly fee, ten-wheelers hired on a share of what they earn, and
 * sub-contractors running their own truck under the fleet's name.
 *
 * All four kinds **dispatch identically**, and that is the reason they are one
 * table rather than four. The board, the driver, the pre-trip check, the proof
 * of delivery and the customer's invoice do not care who owns the wheels, and a
 * second table of hired units would have meant every one of those screens
 * reading two places to answer one question. What differs is only where the
 * money goes when a run closes, so that is all these columns describe.
 *
 * ## The three ways an outside truck is paid for
 *
 *   **Rented.** A flat monthly fee — ₱50,000 is the going rate — and the fleet
 *   keeps everything the truck earns. The fee is a cost of the month rather
 *   than of any run: an idle truck still costs its rent, which is the risk the
 *   fleet took in hiring one.
 *
 *   **Rented on a share.** No rent at all; the fleet keeps a percentage of each
 *   run and the owner takes the rest. What a ten-wheeler owner asks for, at
 *   15%. The mirror of the above — here the *owner* carries the risk of a quiet
 *   month.
 *
 *   **Sub-contracted.** The same arithmetic at 12%, and one difference that
 *   matters: there is a person behind it holding the handset. They are a
 *   `truckers` row, so they see their runs and their wallet and get paid run by
 *   run through machinery that already exists.
 *
 * ## Why the share arrangements point at a `truckers` row
 *
 * Because the money owed to a ten-wheeler's owner and the money owed to a
 * partner trucker are the same debt with the same lifecycle: it accrues per
 * delivered run, it nets against what they owe back, and it is settled a run at
 * a time with a reference. That is a wallet, and there is one already, tested.
 * Giving owners a second, parallel ledger would be two places for one figure to
 * be wrong in.
 *
 * The one thing that had to give is `truckers.licence_no`: an owner who only
 * supplies a truck does not drive it and has no licence to record. It becomes
 * nullable — see below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            /**
             * `owned`, `rented`, `rented_share` or `subcontracted`.
             *
             * Defaulted to `owned`, which backfills every existing row
             * correctly rather than approximately: a fleet that has never
             * recorded this has only ever had its own trucks, so the default
             * *is* the truth about them.
             */
            $table->string('arrangement')->default('owned')->after('status');

            /**
             * How many wheels.
             *
             * On the truck rather than derived from its category, because the
             * category says what it carries (freezer, flatbed) and this says
             * how big it is — a ten-wheeler freezer and a six-wheeler freezer
             * are the same category and different trucks. It is what the desk
             * means by "a ten-wheeler", which is the phrase the rented-share
             * arrangement was described in.
             *
             * Null for a unit nobody has recorded it for. Nothing computes
             * money from it — the rate is on the row below, where it can be
             * negotiated — so a missing value costs nothing but a blank column.
             */
            $table->unsignedTinyInteger('wheels')->nullable()->after('capacity_kg');

            /** Who the fleet pays. Null on its own trucks, which it pays nobody for. */
            $table->string('owner_name')->nullable()->after('arrangement');
            $table->string('owner_contact', 40)->nullable()->after('owner_name');

            /**
             * The monthly fee, in centavos. `rented` only.
             *
             * Nullable rather than defaulted to zero: a rented truck with no
             * fee recorded is a truck somebody forgot to put terms on, and the
             * rent run says so rather than silently billing nothing.
             */
            $table->bigInteger('rent_cents')->nullable()->after('owner_contact');

            /**
             * The fleet's cut of a run, in basis points. The two share
             * arrangements only — 1500 on a rented ten-wheeler, 1200 on a
             * sub-contractor.
             *
             * Per truck rather than per arrangement, because it is a
             * negotiation with whoever owns that one. `VehicleArrangement`
             * carries the usual figure for each, and this is what actually
             * applies.
             */
            $table->unsignedSmallInteger('share_bp')->nullable()->after('rent_cents');

            /**
             * Who the share is owed to.
             *
             * A `truckers` row, because the debt behaves exactly like a
             * partner's — accruing per run, netting, settled a run at a time —
             * and that account already exists and is tested. For a
             * sub-contractor it is the operator, with a login and the handset.
             * For a rented ten-wheeler it is the owner, who may have neither.
             *
             * Null on delete rather than cascade: removing a partner must not
             * take the truck off the fleet with them, and a unit with terms and
             * nobody to pay is a state the office can see and fix.
             */
            $table->foreignUlid('owner_trucker_id')->nullable()->after('share_bp')
                ->constrained('truckers')->nullOnDelete();

            $table->index(['company_id', 'arrangement']);
        });

        /**
         * A partner who supplies a truck but does not drive it has no licence.
         *
         * The column was required because every partner until now was an
         * owner-operator — the whole point of the record was somebody with a
         * truck *and* a licence. A ten-wheeler's owner is a different animal:
         * the fleet pays them for the use of the unit and puts its own driver
         * in it, so demanding a licence would mean inventing one.
         *
         * The unique index over (`company_id`, `licence_no`) is unaffected in
         * the way that matters: SQL does not consider two nulls equal, so any
         * number of owners can sit beside each other and the index still
         * refuses a duplicate licence between people who have one.
         */
        $rebuildsTables = DB::getDriverName() === 'sqlite';

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->dropUnique(['company_id', 'licence_no']);
            });
        }

        Schema::table('truckers', function (Blueprint $table): void {
            $table->string('licence_no')->nullable()->change();
        });

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->unique(['company_id', 'licence_no']);
            });
        }

        /**
         * What the truck's owner took out of the day, on the day's sheet.
         *
         * Beside fuel, the crew and maintenance, because that is what it is: a
         * cost of running that unit on that date. Without it a rented-share
         * truck would show its full income against almost no costs and read as
         * the most profitable thing on the fleet, when in fact the fleet keeps
         * fifteen per cent of it.
         *
         * `FinanceService` writes it from the same figure the wallet is
         * credited with, so Trip Monitoring, Profitability and the partner's
         * own statement are three views of one number rather than three
         * numbers.
         */
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->bigInteger('owner_share_cents')->default(0)->after('allowance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('owner_share_cents');
        });

        $rebuildsTables = DB::getDriverName() === 'sqlite';

        // The column goes back to NOT NULL, so anything that arrived without a
        // licence has to be given something rather than blocking the rollback.
        DB::table('truckers')->whereNull('licence_no')->update(['licence_no' => '']);

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->dropUnique(['company_id', 'licence_no']);
            });
        }

        Schema::table('truckers', function (Blueprint $table): void {
            $table->string('licence_no')->nullable(false)->change();
        });

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->unique(['company_id', 'licence_no']);
            });
        }

        Schema::table('vehicles', function (Blueprint $table) use ($rebuildsTables): void {
            if (! $rebuildsTables) {
                // The key before the index it rests on — see the migration that
                // put a trucker on a trip for why MySQL insists on that order.
                $table->dropForeign(['owner_trucker_id']);
                $table->dropIndex(['company_id', 'arrangement']);

                $table->dropColumn([
                    'arrangement', 'wheels', 'owner_name', 'owner_contact',
                    'rent_cents', 'share_bp', 'owner_trucker_id',
                ]);

                return;
            }

            $table->dropIndex(['company_id', 'arrangement']);
            $table->dropConstrainedForeignId('owner_trucker_id');
            $table->dropColumn([
                'arrangement', 'wheels', 'owner_name', 'owner_contact',
                'rent_cents', 'share_bp',
            ]);
        });
    }
};
