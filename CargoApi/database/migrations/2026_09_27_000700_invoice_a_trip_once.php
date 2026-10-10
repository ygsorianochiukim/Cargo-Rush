<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('invoices')
            ->whereNotNull('trip_id')
            ->select('trip_id', 'direction', DB::raw('count(*) as copies'))
            ->groupBy('trip_id', 'direction')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $numbers = DB::table('invoices')
                ->whereIn('trip_id', $duplicates->pluck('trip_id'))
                ->orderBy('trip_id')
                ->pluck('number')
                ->implode(', ');

            throw new RuntimeException(
                'Some trips were invoiced more than once. Cancel or delete the extra documents '
                ."(then clear their trip_id) before migrating: {$numbers}"
            );
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['trip_id', 'direction'], 'invoices_trip_direction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_trip_direction_unique');
        });
    }
};
