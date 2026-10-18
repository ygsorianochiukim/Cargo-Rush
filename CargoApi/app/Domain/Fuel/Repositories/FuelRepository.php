<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Repositories;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Fuel\Models\FuelBudget;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Support\NumberOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class FuelRepository extends Repository
{
    protected function model(): string
    {
        return FuelRecord::class;
    }

    public function query(): Builder
    {
        return FuelRecord::query()
            ->with(['vehicle:id,plate', 'driver:id,name', 'paymentSource:id,name,kind,last_four'])
            ->orderByDesc('logged_at');
    }

    protected function searchable(): array
    {
        return ['receipt_no'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('logged_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('logged_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }

    public function budgetFor(Carbon $date): ?FuelBudget
    {
        return FuelBudget::query()->whereDate('date', $date)->first();
    }

    public function spentBetween(Carbon $from, Carbon $to): int
    {
        return (int) FuelRecord::query()
            ->whereBetween('logged_at', [$from, $to])
            ->where('status', StatusValue::Active->value)
            ->sum('amount_cents');
    }

    public function tripFuelBetween(Carbon $from, Carbon $to): int
    {
        return (int) DeliveryLog::query()
            ->where('posted_fuel_cents', '>', 0)
            ->whereDate('posted_on', '>=', $from->toDateString())
            ->whereDate('posted_on', '<=', $to->toDateString())
            ->sum('posted_fuel_cents');
    }

    /** @return Collection<int, DeliveryLog> */
    public function tripFuel(int $limit = 200): Collection
    {
        return NumberOrder::newestTripFirst(DeliveryLog::query()
            ->where('posted_fuel_cents', '>', 0)
            ->with(['trip' => static fn ($q) => $q->with(['vehicle:id,plate', 'driver:id,name'])]))
            ->orderByDesc('posted_on')
            ->orderByDesc('delivered_at')
            ->limit($limit)
            ->get();
    }

    public function pendingBetween(Carbon $from, Carbon $to): int
    {
        return (int) FuelRecord::query()
            ->whereBetween('logged_at', [$from, $to])
            ->where('status', StatusValue::Pending->value)
            ->sum('amount_cents');
    }

    /** @return Collection<int, FuelRecord> */
    public function postedBetween(Carbon $from, Carbon $to): Collection
    {
        return FuelRecord::query()
            ->where('posted_cents', '>', 0)
            ->whereNotNull('posted_truck_id')
            ->whereDate('posted_on', '>=', $from->toDateString())
            ->whereDate('posted_on', '<=', $to->toDateString())
            ->get();
    }

    /** @return Collection<int, FuelRecord> */
    public function countedBetween(Carbon $from, Carbon $to): Collection
    {
        return FuelRecord::query()
            ->whereBetween('logged_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('status', StatusValue::Active->value)
            ->get();
    }

    /** @return Collection<int, FuelRecord> */
    public function unpostedBetween(Carbon $from, Carbon $to): Collection
    {
        return $this->countedBetween($from, $to)
            ->filter(static fn (FuelRecord $fill): bool => ! $fill->isPosted())
            ->values();
    }

    public function openRequests(): int
    {
        return FuelRecord::query()->where('status', StatusValue::Pending->value)->count();
    }
}
