<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a trip's distance came from.
 *
 * The distance picks the zone, and three sources can supply it that are not
 * equally good evidence:
 *
 *   `road`      measured on the road network between the two pins;
 *   `estimate`  the straight line times a detour factor, because the routing
 *               service could not be asked — close, and worth a second look;
 *   `manual`    typed by the desk, who knows the route.
 *
 * Null is every trip from before this, and any trip with no distance at all.
 * Recorded because a quote somebody questions has to be answerable with more
 * than "the system said 90 km".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->string('distance_source', 16)->nullable()->after('distance_total_m');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('distance_source');
        });
    }
};
