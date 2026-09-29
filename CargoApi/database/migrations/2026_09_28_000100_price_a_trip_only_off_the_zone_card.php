<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A trip is priced off the zone card, by hand, or not at all yet.
 *
 * Until now a run the card did not cover fell to a flat tariff — base plus
 * per-km plus per-kg — and came out with a figure nobody had agreed to. The
 * office decided that a price the principal never published is worse than no
 * price: the run now waits, visibly, for a zone line or a typed figure.
 *
 * So `price_cents` may be **null**, which means "not priced yet". Zero stays a
 * real price (the company's own freight), so the two could not share a value.
 * The default moves to null with it: a row inserted without a quote has not
 * been priced, and a default of zero would have claimed it had.
 *
 * `pricing_source` is how the figure was reached — `zone` off a line of the
 * card, `manual` typed by somebody who manages the card, `unzoned` for a run
 * waiting on one — and `pricing_note` says why in words the desk can act on
 * ("No zone covers 712 km for a 10-wheeler"). Both null on older rows, whose
 * provenance this migration cannot know and does not guess.
 *
 * Nothing is dropped. The company's four tariff columns stay where they are,
 * unread, so a firm's old figures are still there if anybody asks what they
 * were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_cents')->nullable()->default(null)->change();
            $table->string('pricing_source', 16)->nullable()->after('pricing_bracket_id');
            $table->string('pricing_note', 255)->nullable()->after('pricing_source');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn(['pricing_source', 'pricing_note']);
        });

        // Back to the old contract: every trip carries a number.
        DB::table('trips')->whereNull('price_cents')->update(['price_cents' => 0]);

        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_cents')->default(0)->nullable(false)->change();
        });
    }
};
