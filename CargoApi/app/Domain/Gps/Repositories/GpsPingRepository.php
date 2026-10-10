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

    public function trailForTrip(string $tripId, int $limit = 200): Collection
    {
        return GpsPing::query()
            ->where('trip_id', $tripId)
            ->orderBy('recorded_at')
            ->limit($limit)
            ->get();
    }

    public function pathForTrip(string $tripId, int $limit = 500): Collection
    {
        return GpsPing::query()
            ->select(['id', 'lat', 'lng', 'speed_kph', 'recorded_at'])
            ->where('trip_id', $tripId)
            ->plotted()
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->sortBy('recorded_at')
            ->values();
    }
}
