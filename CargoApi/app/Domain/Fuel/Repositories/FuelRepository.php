<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Repositories;

use App\Domain\Fuel\Models\FuelBudget;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
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
            ->with(['vehicle:id,plate', 'driver:id,name'])
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

    /** Today's allowance row, or null if nobody has set one. */
    public function budgetFor(Carbon $date): ?FuelBudget
    {
        return FuelBudget::query()->whereDate('date', $date)->first();
    }

    /** Cancelled fills are not spend, so they never enter a total. */
    public function spentBetween(Carbon $from, Carbon $to): int
    {
        return (int) FuelRecord::query()
            ->whereBetween('logged_at', [$from, $to])
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->sum('amount_cents');
    }

    public function openRequests(): int
    {
        return FuelRecord::query()->where('status', StatusValue::Pending->value)->count();
    }
}
