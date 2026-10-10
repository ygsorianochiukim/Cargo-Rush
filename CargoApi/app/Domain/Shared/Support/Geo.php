<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

final class Geo
{
    public const EARTH_RADIUS_M = 6_371_000;

    public static function metresBetween(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = $phi2 - $phi1;
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }

    public static function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::metresBetween($lat1, $lng1, $lat2, $lng2) / 1000;
    }

    /** @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float} */
    public static function boundingBox(float $lat, float $lng, float $km): array
    {
        $latSpan = $km / 111.045;
        $lngSpan = $km / max(0.01, 111.045 * cos(deg2rad($lat)));

        return [
            'min_lat' => max(-90, $lat - $latSpan),
            'max_lat' => min(90, $lat + $latSpan),
            'min_lng' => max(-180, $lng - $lngSpan),
            'max_lng' => min(180, $lng + $lngSpan),
        ];
    }
}
