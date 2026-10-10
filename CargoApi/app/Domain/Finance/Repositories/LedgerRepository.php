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

class LedgerRepository extends Repository
{
    protected function model(): string
    {
        return LedgerEntry::class;
    }

    public function query(): Builder
    {
        return LedgerEntry::query()
            ->with(['truck', 'trip:id,reference', 'customer:id,name', 'driver:id,name', 'helpers.driver:id,name'])
            ->orderByDesc('date');
    }

    protected function searchable(): array
    {
        return ['route', 'remarks'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
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

        $attributes['position'] ??= (int) Truck::query()->max('position') + 1;

        return Truck::create($attributes)->refresh();
    }

    public function updateTruck(Truck $truck, TruckData $data): Truck
    {
        $truck->update($data->persistable());

        return $truck->refresh();
    }

    public function entriesBetween(Carbon $from, Carbon $to): Collection
    {
        return LedgerEntry::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->get();
    }

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
