<?php

declare(strict_types=1);

namespace App\Domain\Gps\Services;

use App\Domain\Gps\DTO\GpsPingData;
use App\Domain\Gps\Models\GpsPing;
use App\Domain\Gps\Repositories\GpsPingRepository;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use Illuminate\Database\Eloquent\Collection;

class GpsService
{
    public function __construct(
        private readonly GpsPingRepository $pings,
        private readonly TripRepository $trips,
    ) {}

    public function liveUnits(): Collection
    {
        return $this->trips->onTheRoad();
    }

    public function record(GpsPingData $data): GpsPing
    {
        return $this->pings->create($data);
    }

    /** @return array<string, mixed>|null */
    public function trackingState(Trip $trip): ?array
    {
        $latest = $this->pings->latestForTrip($trip->id);

        if ($latest === null) {
            return null;
        }

        return [
            'reference' => $trip->reference,
            'point_a' => $trip->pickup_place ?? $trip->origin,
            'point_b' => $trip->dropoff_place ?? $trip->destination,
            'current_location' => $latest->location,
            'progress_pct' => $latest->progress_pct,
            'speed_kph' => $latest->speed_kph,
            'average_speed_kph' => $this->averageSpeed($trip),
            'distance_done_m' => $latest->distance_done_m,
            'distance_total_m' => $trip->distance_total_m,
            'eta' => $trip->eta?->format('Y-m-d\TH:i:s\Z'),

            'current' => $latest->isPlotted()
                ? ['lat' => $latest->lat, 'lng' => $latest->lng]
                : null,
            'path' => $this->path($trip),
            'endpoints' => [
                'origin' => $trip->origin_lat === null
                    ? null
                    : ['lat' => (float) $trip->origin_lat, 'lng' => (float) $trip->origin_lng],
                'destination' => $trip->destination_lat === null
                    ? null
                    : ['lat' => (float) $trip->destination_lat, 'lng' => (float) $trip->destination_lng],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function path(Trip $trip): array
    {
        return $this->pings->pathForTrip($trip->id)
            ->map(static fn (GpsPing $ping): array => [
                'lat' => $ping->lat,
                'lng' => $ping->lng,
                'speed_kph' => $ping->speed_kph,
                'recorded_at' => $ping->recorded_at->format('Y-m-d\TH:i:s\Z'),
            ])
            ->all();
    }

    public function averageSpeed(Trip $trip): int
    {
        $trail = $this->pings->trailForTrip($trip->id);

        if ($trail->count() < 2) {
            return $trail->first()?->speed_kph ?? 0;
        }

        $first = $trail->first();
        $last = $trail->last();

        $metres = max(0, $last->distance_done_m - $first->distance_done_m);
        $hours = $first->recorded_at->diffInSeconds($last->recorded_at) / 3600;

        if ($hours <= 0) {
            return 0;
        }

        return (int) round(($metres / 1000) / $hours);
    }
}
