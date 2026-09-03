<?php

declare(strict_types=1);

namespace App\Domain\Gps\Services;

use App\Domain\Gps\DTO\GpsPingData;
use App\Domain\Gps\Models\GpsPing;
use App\Domain\Gps\Repositories\GpsPingRepository;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Live position, from the handset to the back office.
 *
 * The driver app is the only writer (DESIGN.md section 5.4); everything the
 * web GPS Dashboard shows is derived from the pings it posts.
 */
class GpsService
{
    public function __construct(
        private readonly GpsPingRepository $pings,
        private readonly TripRepository $trips,
    ) {}

    /** One row per unit on the road, latest position attached. */
    public function liveUnits(): Collection
    {
        return $this->trips->onTheRoad();
    }

    public function record(GpsPingData $data): GpsPing
    {
        return $this->pings->create($data);
    }

    /**
     * The mobile Tracking screen: A to B, how far along, and how fast on
     * average rather than right now.
     *
     * @return array<string, mixed>|null
     */
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
        ];
    }

    /**
     * Distance covered over time elapsed.
     *
     * Deliberately not the mean of the reported speeds: a unit parked in a
     * port queue reports 0 every minute, which would drag a mean of samples
     * far below the speed the run actually averaged.
     */
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
