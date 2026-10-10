<?php

declare(strict_types=1);

use App\Domain\Gps\Models\GpsPing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_pings', function (Blueprint $table): void {
            $table->decimal('lat', 10, 7)->nullable()->after('location');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');

            $table->index(['trip_id', 'recorded_at', 'lat', 'lng'], 'gps_pings_path_index');
        });

        $this->backfillFromLocation();
    }

    public function down(): void
    {
        Schema::table('gps_pings', function (Blueprint $table): void {
            $table->dropIndex('gps_pings_path_index');
            $table->dropColumn(['lat', 'lng']);
        });
    }

    private function backfillFromLocation(): void
    {
        DB::table('gps_pings')
            ->select(['id', 'location'])
            ->whereNull('lat')
            ->where('location', 'like', '%,%')
            ->orderBy('id')
            ->chunkById(1000, function ($pings): void {
                foreach ($pings as $ping) {
                    $coordinates = GpsPing::coordinatesFromLocation($ping->location);

                    if ($coordinates === null) {
                        continue;
                    }

                    DB::table('gps_pings')->where('id', $ping->id)->update($coordinates);
                }
            });
    }
};
