<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A zone is a **distance band**, not a place.
 *
 * This is how the trade's own subsidy tables are written, and the workbook
 * this was corrected against is a plain example of it — one sheet per class of
 * truck, and down the left a column headed `Zone` holding A1, A2, B, C … O
 * with a kilometre range beside each:
 *
 *     Zone   Distance        Current Price   per ₱1/L diesel
 *     A1     1   to    40         4,165            14
 *     A2     1   to    40         4,412            19
 *     B      41  to    80         5,230            28
 *     …
 *     O      561 to   600        37,206           210
 *
 * The zone *is* the band. Nothing about it names a town, and two zones can
 * share a band — A1 and A2 both run 1–40 km at different money, as do E1 and
 * E2 — because the split between them is a commercial decision the desk makes,
 * not something a distance or a destination can decide.
 *
 * What was here before matched a zone from a booking's free-text destination
 * and then looked for a distance bracket *inside* it. That is the wrong way
 * round twice over: it made the place the thing everything hung off, and it
 * could not express A1 beside A2 at all, since the two are identical in every
 * respect a destination string can see.
 *
 * ## The three changes
 *
 * **`pricing_zones` carries the band.** `min_km` and `max_km`, half-open like
 * every other range in this module, so the 17 rows of a subsidy table are 17
 * zones and a quote picks one by how far the run is.
 *
 * **`aliases` is gone.** It existed only to match a destination, and matching a
 * destination is no longer how a zone is chosen. Dropping it rather than
 * leaving it unread: a column that still accepts the town names somebody typed
 * but no longer prices anything from them is worse than no column, because the
 * office cannot tell by looking that it stopped mattering.
 *
 * **A bracket's band becomes optional.** `min_km`/`max_km` go nullable, and
 * null now means "the whole of my zone's band". A zone's card is then a rate
 * line per class of truck — which is exactly what a row of the workbook is,
 * read across — while the zoneless card keeps its own kilometres and prices
 * runs the way it always did.
 *
 * ## Diesel, as the table actually states it
 *
 * `pricing_brackets.diesel_step_cents` is the workbook's rightmost header
 * column: **pesos added per ₱1/L of diesel above the baseline**. A1 adds ₱14 a
 * peso, O adds ₱210, and at ₱85/L against a ₱43 baseline A1 comes out at
 * 4,165 + 14 × 42 = ₱4,753 — which is the figure printed in the table.
 *
 * That is a flat amount per band, not a percentage of the fare, and the
 * percentage model already here cannot produce it: scaling every band by one
 * fuel share gives a surcharge proportional to the base, and the table's is
 * not. ₱14 on ₱4,165 is 0.34% a peso; ₱210 on ₱37,206 is 0.56%.
 *
 * So the step wins **where a line declares one**, and the percentage model is
 * left in place for lines that do not. An install pricing off `sensitivity`
 * and `cap_bp` today keeps precisely the quotes it has; a card drawn from a
 * subsidy table gets the table's arithmetic. See `FuelIndex`.
 *
 * `trips.fuel_surcharge_cents` stores what the step added, in centavos. The
 * existing `fuel_adjustment_bp` stays and is still what the percentage path
 * records — but a flat peso surcharge expressed as basis points of the base is
 * a rounded number, and the one column somebody will be asked to justify to a
 * customer should be the exact one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_zones', function (Blueprint $table): void {
            /**
             * The band, half-open: `min_km` inclusive, `max_km` exclusive.
             *
             * The workbook writes its bands closed — "1 to 40", "41 to 80" —
             * and they land here as [1, 41) and [41, 81). Same rows, and a run
             * of exactly 41 km belongs to one band rather than to both or to
             * neither. Those two bugs are indistinguishable from the outside,
             * which is why this module has only ever had one convention.
             */
            $table->unsignedInteger('min_km')->default(0)->after('code');
            /** Null is the open-ended top band — "561 km and beyond". */
            $table->unsignedInteger('max_km')->nullable()->after('min_km');

            $table->index(['company_id', 'min_km']);
        });

        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->dropColumn('aliases');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            /**
             * Pesos-per-peso, in centavos: what this line adds for every ₱1/L
             * diesel sits above the baseline.
             *
             * Zero — the default, and what every existing line becomes — means
             * "no step declared", and the line keeps the percentage
             * adjustment. There is no way to say "a step of exactly nothing",
             * and nothing in a subsidy table wants to: a band with no fuel
             * sensitivity at all is a band whose price does not move, which is
             * what a zero base step already gives.
             */
            $table->unsignedBigInteger('diesel_step_cents')->default(0)->after('minimum_cents');
        });

        /**
         * A bracket's own band becomes optional.
         *
         * Null means "the whole of my zone's band", which is what every line
         * on a subsidy-table card is. Existing rows all hold real numbers and
         * are untouched, so a card drawn before this keeps its kilometres and
         * prices exactly as it did.
         */
        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->unsignedInteger('min_km')->nullable()->default(null)->change();
        });

        Schema::table('trips', function (Blueprint $table): void {
            /**
             * What the diesel step added to this trip, in centavos.
             *
             * Beside `fuel_adjustment_bp` rather than instead of it, because
             * the two answer different questions and only one of them applies
             * to any given quote. A trip priced off a step has the exact pesos
             * here and a zero there; a trip priced off the percentage model
             * has the reverse.
             */
            $table->integer('fuel_surcharge_cents')->default(0)->after('fuel_adjustment_bp');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('fuel_surcharge_cents');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->dropColumn('diesel_step_cents');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->unsignedInteger('min_km')->default(0)->change();
        });

        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->json('aliases')->nullable()->after('code');
        });

        Schema::table('pricing_zones', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'min_km']);
            $table->dropColumn(['min_km', 'max_km']);
        });
    }
};
