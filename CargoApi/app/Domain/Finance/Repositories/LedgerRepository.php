<?php

declare(strict_types=1);

namespace App\Domain\Finance\Repositories;

use App\Domain\Finance\DTO\TruckData;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The workbook, as queries.
 *
 * This layer only ever fetches rows — the two formulas that turn them into
 * money live in the service, so Profitability and Quarterly Summary cannot
 * drift apart by each adding things up their own way.
 */
class LedgerRepository extends Repository
{
    protected function model(): string
    {
        return LedgerEntry::class;
    }

    public function query(): Builder
    {
        // `trip` travels with the row because the resource prints its
        // reference, and a ledger page is a list — one query apiece would be
        // an N+1 the moment a sheet has a month on it.
        return LedgerEntry::query()
            ->with(['truck', 'trip:id,reference', 'customer:id,name'])
            ->orderByDesc('date');
    }

    protected function searchable(): array
    {
        return ['route', 'remarks'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        // `status` means nothing on a ledger row, so the base filter is skipped.
        if (! empty($filters['truck_id'])) {
            $query->where('truck_id', $filters['truck_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', Carbon::parse($filters['to']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q
                ->where('route', 'like', $term)
                ->orWhere('remarks', 'like', $term));
        }

        return $query;
    }

    /** Every unit, including the two with no plate yet. */
    public function trucks(): Collection
    {
        return Truck::query()->orderBy('position')->get();
    }

    public function findTruck(string $id): ?Truck
    {
        return Truck::query()->find($id);
    }

    public function createTruck(TruckData $data): Truck
    {
        $attributes = $data->persistable();

        // Appended rather than inserted, so adding a unit does not renumber
        // the sheets somebody already knows the order of.
        $attributes['position'] ??= (int) Truck::query()->max('position') + 1;

        return Truck::create($attributes)->refresh();
    }

    public function updateTruck(Truck $truck, TruckData $data): Truck
    {
        $truck->update($data->persistable());

        return $truck->refresh();
    }

    /** The rows a period roll-up is computed from. */
    public function entriesBetween(Carbon $from, Carbon $to): Collection
    {
        return LedgerEntry::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->get();
    }

    /** Routes already used, to suggest in the entry form. */
    public function knownRoutes(): array
    {
        return LedgerEntry::query()
            ->whereNotNull('route')
            ->distinct()
            ->orderBy('route')
            ->pluck('route')
            ->all();
    }
}
