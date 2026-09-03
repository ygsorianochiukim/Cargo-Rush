<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a trip starts and ends, as a point on the earth.
 *
 * `origin` and `destination` already hold the place name a person reads. These
 * add the coordinates behind them, so a route can be drawn, a distance can be
 * computed rather than typed, and a driver's reported position can be measured
 * against something.
 *
 * Nullable, because a trip booked over the phone has a place name long before
 * anybody has pinned it on a map — and a trip you cannot book until you have
 * is a worse trip form.
 *
 * `decimal(10, 7)` is roughly 11mm of precision, which is far finer than a
 * depot gate needs and avoids the drift a float would bring to a value that
 * gets compared for equality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->decimal('origin_lat', 10, 7)->nullable()->after('origin');
            $table->decimal('origin_lng', 10, 7)->nullable()->after('origin_lat');
            $table->decimal('destination_lat', 10, 7)->nullable()->after('destination');
            $table->decimal('destination_lng', 10, 7)->nullable()->after('destination_lat');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn([
                'origin_lat', 'origin_lng',
                'destination_lat', 'destination_lng',
            ]);
        });
    }
};
