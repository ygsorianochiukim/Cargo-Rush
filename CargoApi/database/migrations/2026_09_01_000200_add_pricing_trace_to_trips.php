<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why this trip cost what it cost.
 *
 * `price_cents` alone cannot answer that once the figure is derived from an
 * editable rate card and a moving fuel price: the card is corrected, the pump
 * price changes, and the quote a customer accepted last month can no longer be
 * reproduced from today's rows. These two columns are the receipt — which
 * bracket priced it, and how much of the figure was the diesel adjustment.
 *
 * Basis points rather than a float percentage, for the same reason money is
 * centavos: 425 is exact and 0.0425 is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignUlid('pricing_zone_id')->nullable()->after('currency')
                ->constrained('pricing_zones')->nullOnDelete();
            $table->foreignUlid('pricing_bracket_id')->nullable()->after('pricing_zone_id')
                ->constrained('pricing_brackets')->nullOnDelete();
            // Signed: diesel below the baseline is a discount, and a surcharge
            // that could only ever be positive would quietly keep charging for
            // a price rise that has since reversed.
            $table->integer('fuel_adjustment_bp')->default(0)->after('pricing_bracket_id');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pricing_zone_id');
            $table->dropConstrainedForeignId('pricing_bracket_id');
            $table->dropColumn('fuel_adjustment_bp');
        });
    }
};
