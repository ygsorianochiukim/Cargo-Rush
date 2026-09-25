<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Repositories;

use App\Domain\Shared\Repositories\Repository;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Servicing across the whole fleet, rather than one unit at a time.
 *
 * The per-vehicle list has always existed — it is what the unit's own screen
 * shows — and it answers a yard question: what is booked on this truck. Truck
 * Maintenance asks the office's question instead: what has the fleet spent on
 * servicing, and which units did it go on. Same rows, read the other way round,
 * which is why this is a query and not a second table.
 */
class MaintenanceRepository extends Repository
{
    protected function model(): string
    {
        return MaintenanceJob::class;
    }

    /**
     * Newest work first, and booked-but-not-done last.
     *
     * A costed job is history and sorts by the day the work happened; a job
     * with no `completed_on` has not happened yet, so it sorts by when it is
     * due. Ordering everything by `due_at` alone would file last week's engine
     * rebuild between two oil changes booked for next month.
     */
    public function query(): Builder
    {
        return MaintenanceJob::query()
            // Both names are printed on every row — the plate and the garage —
            // and without them a page of jobs is a page of queries.
            ->with(['vehicle:id,plate,model,odometer_km', 'supplier:id,name'])
            ->orderByRaw('completed_on is null desc')
            ->orderByDesc('completed_on')
            ->orderBy('due_at');
    }

    protected function searchable(): array
    {
        return ['kind', 'reference', 'note'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        /**
         * A window over **the day the job belongs to**.
         *
         * Which is `completed_on` where there is one and `due_at` where there
         * is not, because those are the two answers to "when": the day the work
         * was done, or the day it is booked for. Filtering on `due_at` alone
         * would drop a service booked in August and done in September out of a
         * September spend window — which is the month the money actually left.
         */
        if (! empty($filters['from'])) {
            $from = Carbon::parse($filters['from'])->toDateString();

            $query->where(fn (Builder $q) => $q
                ->whereDate('completed_on', '>=', $from)
                ->orWhere(fn (Builder $booked) => $booked
                    ->whereNull('completed_on')
                    ->whereDate('due_at', '>=', $from)));
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse($filters['to'])->toDateString();

            $query->where(fn (Builder $q) => $q
                ->whereDate('completed_on', '<=', $to)
                ->orWhere(fn (Builder $booked) => $booked
                    ->whereNull('completed_on')
                    ->whereDate('due_at', '<=', $to)));
        }

        /**
         * Only what somebody has been charged for.
         *
         * The one filter the Truck Maintenance screen adds over the yard's
         * list: a booked job costs nothing yet, and a page meant to answer
         * "what did servicing come to" should be able to leave them out
         * without also hiding a warranty job that genuinely cost nought.
         */
        if (array_key_exists('costed', $filters) && $filters['costed'] !== '') {
            $query->{filter_var($filters['costed'], FILTER_VALIDATE_BOOLEAN) ? 'whereNotNull' : 'whereNull'}('cost_cents');
        }

        return $query;
    }

    /** What servicing has come to over a window, in centavos. */
    public function costedBetween(array $filters = []): int
    {
        return (int) $this->applyFilters(MaintenanceJob::query(), $filters)->sum('cost_cents');
    }
}
