<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Shared\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoadDistance
{
    /** @return array{metres: int, source: 'road'|'estimate'} */
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
                ->post($url, ['coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]]]);
        } catch (\Throwable $e) {
            Log::warning('Road distance: routing service unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $metres = $response->successful() ? $response->json('routes.0.summary.distance') : null;

        if (! is_numeric($metres)) {
            Log::warning('Road distance: no route', [
                'status' => $response->status(),
                'error' => $response->json('error.message') ?? $response->json('error'),
            ]);

            return null;
        }

        return (int) round((float) $metres);
    }
}
