<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing by **distance and kind of truck**, with the place as an optional
 * refinement rather than the thing everything hangs off.
 *
 * The rate card was built place-first: a booking's destination picked a zone by
 * matching free text, and the distance brackets lived *inside* that zone. So a
 * firm whose price is simply "450 km is ₱5,000" could not say that. It had to
 * invent a zone per town first, and a destination no zone claimed fell through
 * to the flat config tariff — quietly, and at a different number.
 *
 * Two changes open it up.
 *
 * ## A bracket no longer needs a zone
 *
 * `pricing_brackets.zone_id` becomes **nullable**, and a bracket without one is
 * the firm's plain distance card: it applies wherever nothing more specific
 * does. That is the whole of the ask — an office can now price the way it
 * actually quotes, by how far the run is, and never open the zone editor at
 * all. Zones stay for the firms that do price Davao differently from Tagum,
 * and are now what they should always have been: an exception to a default,
 * not the only way in.
 *
 * ## A freezer is not a flatbed
 *
 * `truck_categories` is the kind of unit a job needs — Dry Goods, Freezer,
 * Flatbed — and a bracket may name one. Reefer work carries a premium that has
 * nothing to do with distance, and a card that cannot express it forces the
 * desk to quote off-system and type the figure in, which is the state this
 * module exists to end.
 *
 * Per company, like `positions` and `roles`: what a haulier calls its unit
 * types and what it charges for them is nobody else's business.
 *
 * ## How the two combine
 *
 * A quote takes the **most specific** bracket that covers the distance:
 *
 *     zone + category   →   "Davao, freezer, 0–50 km"
 *     zone only         →   "Davao, 0–50 km"
 *     category only     →   "Freezer, anywhere, 0–50 km"
 *     neither           →   "0–50 km"          ← the plain distance card
 *
 * Falling through to the config tariff stays the last resort, so an install
 * that never opens the editor prices exactly as it did before and nothing here
 * changes an existing card: every bracket already has a `zone_id`, and a null
 * `truck_category_id` means "any", which is what they all were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /** `freezer`, `dry-goods` — the handle a seeder finds it by. */
            $table->string('key');
            /** "Freezer / Reefer" — what the desk calls it. */
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            // Per company, so two hauliers can both have a "Freezer".
            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            /**
             * The kind of unit this line prices. Null means any.
             *
             * Null on delete rather than cascade: removing a category should
             * widen the line to "any truck", not silently delete a price the
             * office is quoting from.
             */
            $table->foreignUlid('truck_category_id')->nullable()->after('zone_id')
                ->constrained('truck_categories')->nullOnDelete();

            $table->index(['company_id', 'truck_category_id']);
        });

        /**
         * `zone_id` becomes nullable.
         *
         * Rebuilt rather than `->change()`d: the column carries a foreign key
         * and SQLite — which the test suite runs on — cannot alter a
         * constrained column in place. Dropping and re-adding it keeps the
         * constraint explicit on both databases, and there is nothing to
         * preserve because the values are re-copied around it.
         */
        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->dropForeign(['zone_id']);
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->ulid('zone_id')->nullable()->change();
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->foreign('zone_id')->references('id')->on('pricing_zones')->cascadeOnDelete();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            /**
             * What kind of unit this is.
             *
             * Not used for pricing — a quote is made before a vehicle is
             * assigned, and often before one exists — but it is what lets
             * dispatch put a freezer job on a freezer truck rather than
             * trusting whoever reads the plate.
             */
            $table->foreignUlid('truck_category_id')->nullable()->after('company_id')
                ->constrained('truck_categories')->nullOnDelete();
        });

        Schema::table('trips', function (Blueprint $table): void {
            /**
             * The kind of unit the job needs, as asked for at booking.
             *
             * On the trip rather than read off its vehicle, and the distinction
             * is the whole reason it is here: a trip is **quoted at booking**,
             * when there is usually no vehicle yet. A customer asking for a
             * freezer is stating a requirement, and the price follows the
             * requirement — not whatever unit the yard happens to assign three
             * days later.
             */
            $table->foreignUlid('truck_category_id')->nullable()->after('vehicle_id')
                ->constrained('truck_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('truck_category_id');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('truck_category_id');
        });

        Schema::table('pricing_brackets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('truck_category_id');
        });

        Schema::dropIfExists('truck_categories');
    }
};
