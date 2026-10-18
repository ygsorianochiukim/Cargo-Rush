<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Repositories;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Support\NumberOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DeliveryLogRepository extends Repository
{
    protected function model(): string
    {
        return DeliveryLog::class;
    }

    public function query(): Builder
    {
        return NumberOrder::newestTripFirst(DeliveryLog::query()
            ->with(['trip:id,reference,destination,customer_id,driver_id',
                'trip.customer:id,name', 'trip.driver:id,name', 'trip.helpers',
                'paymentSource:id,name,kind,last_four']))
            ->orderByDesc('delivered_at');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('trip', fn (Builder $q) => $q->where('reference', 'like', $term));
        }

        if (! empty($filters['driver_id'])) {
            $query->whereHas('trip', fn (Builder $q) => $q
                ->where('driver_id', $filters['driver_id'])
                ->orWhereHas('helpers', fn (Builder $h) => $h->whereKey($filters['driver_id'])));
        }

        return $query;
    }

    public function forDriver(string $driverId): Collection
    {
        return $this->all(['driver_id' => $driverId]);
    }

    public function deliveredPerDay(int $days = 7): Collection
    {
        return DeliveryLog::query()
            ->selectRaw('date(delivered_at) as day, count(*) as delivered')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }
}
