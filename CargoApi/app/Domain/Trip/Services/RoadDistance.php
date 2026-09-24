<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Shared\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * How far a truck actually drives between two pins.
 *
 * The distance a trip carries picks its zone, and a zone is a band of
 * kilometres — so a distance that is wrong by a band is a price that is wrong
 * by thousands of pesos. The straight line between the pins was that distance
 * until this existed, and it is always short: CDO to Iligan is 49 km in a
 * straight line and about 90 on the road, which is band B quoted for a band C
 * run.
 *
 * OpenRouteService answers on the road network, with its heavy-goods profile:
 * the roads a ten-wheeler may use, not a car's. When it cannot be asked — no
 * key on this install, the service down, two pins it cannot join by road —
 * the answer is the straight line times `cargo.routing.detour_factor`, and the
 * result says so. A caller records which it got, because "road" and "estimate"
 * are different grades of evidence for a figure somebody may be asked to
 * justify on an invoice.
 *
 * Cached by the pair of pins, rounded to four decimal places (about eleven
 * metres). A depot booked to the same warehouse every week is one call, not
 * fifty-two, and the free tier's daily allowance is spent on new routes.
 */
class RoadDistance
{
    /**
     * @return array{metres: int, source: 'road'|'estimate'}
     */
    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $road = $this->fromService($fromLat, $fromLng, $toLat, $toLng);

        if ($road !== null) {
            return ['metres' => $road, 'source' => 'road'];
        }

        $straight = Geo::metresBetween($fromLat, $fromLng, $toLat, $toLng);

        return [
            'metres' => (int) round($straight * (float) config('cargo.routing.detour_factor', 1.4)),
            'source' => 'estimate',
        ];
    }

    /** The road distance in metres, or null when the service could not say. */
    private function fromService(float $fromLat, float $fromLng, float $toLat, float $toLng): ?int
    {
        $key = (string) config('cargo.routing.ors_key', '');

        if ($key === '') {
            return null;
        }

        $cacheKey = 'road-distance:'.implode(',', array_map(
            static fn (float $v): string => number_format($v, 4, '.', ''),
            [$fromLat, $fromLng, $toLat, $toLng],
        ));

        // Only a real answer is remembered. Caching a failure would keep a
        // route on the estimate for three months because the service blinked
        // once.
        $cached = Cache::get($cacheKey);

        if (is_int($cached)) {
            return $cached;
        }

        $metres = $this->ask($key, $fromLat, $fromLng, $toLat, $toLng);

        if ($metres !== null) {
            Cache::put($cacheKey, $metres, now()->addDays((int) config('cargo.routing.cache_days', 90)));
        }

        return $metres;
    }

    private function ask(string $key, float $fromLat, float $fromLng, float $toLat, float $toLng): ?int
    {
        $url = rtrim((string) config('cargo.routing.ors_url'), '/')
            .'/v2/directions/'.config('cargo.routing.profile', 'driving-hgv');

        try {
            $response = Http::withHeaders(['Authorization' => $key])
                ->acceptJson()
                ->timeout((int) config('cargo.routing.timeout', 6))
                // Longitude first — GeoJSON order, which is what ORS takes.
                ->post($url, ['coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]]]);
        } catch (\Throwable $e) {
            Log::warning('Road distance: routing service unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $metres = $response->successful() ? $response->json('routes.0.summary.distance') : null;

        if (! is_numeric($metres)) {
            // A 404 here usually means a pin in the sea or on an island with no
            // road to the other one. Logged, because a run of these is a key
            // that has run out of allowance rather than a bad pin.
            Log::warning('Road distance: no route', [
                'status' => $response->status(),
                'error' => $response->json('error.message') ?? $response->json('error'),
            ]);

            return null;
        }

        return (int) round((float) $metres);
    }
}
