<?php

declare(strict_types=1);

namespace App\Domain\Gps\Repositories;

use App\Domain\Gps\Models\GpsPing;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GpsPingRepository extends Repository
{
    protected function model(): string
    {
        return GpsPing::class;
    }

    public function query(): Builder
    {
        return GpsPing::query()->orderByDesc('recorded_at');
    }

    public function latestForTrip(string $tripId): ?GpsPing
    {
        return $this->query()->where('trip_id', $tripId)->first();
    }

    /** The run so far, oldest first, for average speed and the track line. */
    public function trailForTrip(string $tripId, int $limit = 200): Collection
    {
        return GpsPing::query()
            ->where('trip_id', $tripId)
            ->orderBy('recorded_at')
            ->limit($limit)
            ->get();
    }
}
