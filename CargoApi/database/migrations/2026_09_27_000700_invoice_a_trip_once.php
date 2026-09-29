<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One receivable per haul, enforced by the database.
 *
 * `BillingService::raiseForTrip()` already looks before it raises, but a look
 * is a read, and two completions of the same trip racing each other can both
 * find nothing and both insert. This index is what makes the second insert
 * fail; the service catches that and returns the first document.
 *
 * Nulls are allowed, and many: an invoice raised by hand has no trip, and the
 * index says nothing about those. Soft-deleted rows are covered too — which is
 * why `raiseForTrip()` reads `withTrashed()` before deciding.
 *
 * ## Existing duplicates
 *
 * On a fresh database there are none. On one that has been running, a pair
 * would make the index fail half-way through a deploy, so they are looked for
 * first and the migration stops with their numbers rather than choosing which
 * of two issued documents to throw away — that is a decision about money a
 * person has to make.
 */
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
