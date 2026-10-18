<?php

declare(strict_types=1);

namespace App\Domain\Incident\Repositories;

use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Support\NumberOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class IncidentRepository extends Repository
{
    protected function model(): string
    {
        return Incident::class;
    }

    public function query(): Builder
    {
        return NumberOrder::newestFirst(Incident::query()
            ->with(['driver:id,name', 'vehicle:id,plate', 'trip:id,reference']), 'reference')
            ->orderByDesc('occurred_at');
    }

    protected function searchable(): array
    {
        return ['reference', 'kind', 'place'];
    }

    public function paginateForDriver(string $driverId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()->where('driver_id', $driverId)->paginate($perPage);
    }

    public function openCount(): int
    {
        return Incident::query()
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Active->value])
            ->count();
    }

    public function countForDriverBetween(string $driverId, Carbon $from, Carbon $to): int
    {
        return Incident::query()
            ->where('driver_id', $driverId)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }
}
