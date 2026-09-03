<?php

declare(strict_types=1);

namespace App\Domain\Incident\Repositories;

use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class IncidentRepository extends Repository
{
    protected function model(): string
    {
        return Incident::class;
    }

    public function query(): Builder
    {
        return Incident::query()
            ->with(['driver:id,name', 'vehicle:id,plate', 'trip:id,reference'])
            ->orderByDesc('occurred_at');
    }

    protected function searchable(): array
    {
        return ['reference', 'kind', 'place'];
    }

    /** Anything not yet closed out — the sidebar badge and the KPI tile. */
    public function openCount(): int
    {
        return Incident::query()
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Active->value])
            ->count();
    }

    /**
     * How many incidents a crew member was involved in over a window.
     *
     * Every status counts, including the closed ones: a resolved incident still
     * happened, and a performance figure that quietly drops them would improve
     * every time the office finished its paperwork.
     */
    public function countForDriverBetween(string $driverId, Carbon $from, Carbon $to): int
    {
        return Incident::query()
            ->where('driver_id', $driverId)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }
}
