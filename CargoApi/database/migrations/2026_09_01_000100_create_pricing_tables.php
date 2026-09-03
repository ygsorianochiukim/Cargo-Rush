<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate card, as rows the office can edit.
 *
 * A single tariff in `config/cargo.php` charges the same base for a run across
 * town and a run to the next province, which is not how anybody in this trade
 * quotes. The desk works from a rate card: a service area, and inside it a few
 * distance brackets with a price each. That card was living in a spreadsheet
 * beside the system, so the figure a customer was quoted and the figure the
 * system billed were two different numbers maintained by two different people.
 *
 * `pricing_zones` is the service area — "Davao City", "Tagum" — matched from
 * what the booking says its destination is. `pricing_brackets` is the card
 * inside it: `within this many km, this is the price`. The config tariff stays
 * as the fallback for a destination no zone claims, so an install that never
 * opens this editor prices exactly as it did before.
 *
 * `diesel_prices` is the other half of the ask: pump price moves, and a rate
 * card that has to be retyped every time it does will simply be out of date.
 * One row per day, and the quote is adjusted by how far today's price sits
 * from the baseline the card was drawn at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // What the desk calls it. Also the first thing matched against a
            // booking's destination, before the aliases below.
            $table->string('name');
            $table->string('code')->unique();
            /**
             * The other spellings of this place, as a JSON array.
             *
             * A booking's destination is free text typed by whoever took the
             * call — "Davao", "Davao City", "DVO", "Bajada, Davao". Matching
             * only the zone name would drop most of them onto the fallback
             * tariff, which is the silent kind of wrong: the trip still gets
             * a price, just not the one on the card.
             */
            $table->json('aliases')->nullable();
            // The baseline pump price this card's figures were drawn at. Null
            // falls back to the configured one, which is the common case —
            // per-zone only matters where fuel is bought at a different price.
            $table->unsignedInteger('diesel_baseline_cents')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('pricing_brackets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('zone_id')->constrained('pricing_zones')->cascadeOnDelete();
            // "Within 20 km" — what the row is called on the card.
            $table->string('label');
            /**
             * Half-open: `min_km` inclusive, `max_km` exclusive, null being no
             * upper bound. A closed range on both ends leaves a trip that lands
             * exactly on a boundary matching two brackets or none, and the two
             * bugs look identical from the outside.
             */
            $table->unsignedInteger('min_km')->default(0);
            $table->unsignedInteger('max_km')->nullable();
            // Centavos, like every other money column (DESIGN.md section 7.1).
            $table->unsignedBigInteger('base_cents')->default(0);
            $table->unsignedBigInteger('per_km_cents')->default(0);
            $table->unsignedBigInteger('per_kg_cents')->default(0);
            $table->unsignedBigInteger('minimum_cents')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['zone_id', 'min_km']);
        });

        Schema::create('diesel_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // One reading per day. Unique, so recording today twice corrects
            // the figure rather than leaving two prices for the same day and
            // no rule for which one a quote should use.
            $table->date('effective_on')->unique();
            $table->unsignedInteger('price_per_litre_cents');
            $table->string('currency', 3)->default('PHP');
            // Where the figure came from — a station, a bulletin. The office
            // is going to be asked to justify a surcharge eventually.
            $table->string('source')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diesel_prices');
        Schema::dropIfExists('pricing_brackets');
        Schema::dropIfExists('pricing_zones');
    }
};
