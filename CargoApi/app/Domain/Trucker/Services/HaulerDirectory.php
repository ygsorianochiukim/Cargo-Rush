<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Collection;

class HaulerDirectory
{
    /** @return Collection<int, array<string, mixed>> */
    public function near(Company $fleet, ?float $lat = null, ?float $lng = null): Collection
    {
        $radiusKm = (float) config('cargo.truckers.customer_radius_km', 60);

        $truckers = Trucker::query()
            ->with('vehicles')
            ->takingWork()
            ->get()
            ->map(fn (Trucker $trucker): ?array => $this->listing($trucker, $lat, $lng, $radiusKm))
            ->filter()
            ->sortBy('distance_km')
            ->values();

        return collect([$this->fleetListing($fleet, $lat, $lng)])->concat($truckers);
    }

    /** @return array<string, mixed>|null */
    private function listing(Trucker $trucker, ?float $lat, ?float $lng, float $radiusKm): ?array
    {
        $unit = $trucker->activeVehicle();

        if ($unit === null) {
            return null;
        }

        $distanceKm = null;

        if ($lat !== null && $lng !== null) {
            $metres = $trucker->metresTo($lat, $lng);

            if ($metres === null || $metres > $radiusKm * 1000) {
                return null;
            }

            $distanceKm = round($metres / 1000, 1);
        }

        return [
            'kind' => 'trucker',
            'id' => $trucker->getKey(),
            'name' => $trucker->name,
            'distance_km' => $distanceKm,
            'capacity_kg' => $unit->capacity_kg,
            'vehicle' => $unit->model,
            'plate' => $unit->plate,
            'truck_category' => $unit->category?->name,
            'truck_size' => $unit->size?->name,
            'trips_completed' => $trucker->trips_completed,
        ];
    }

    /** @return array<string, mixed> */
    private function fleetListing(Company $fleet, ?float $lat, ?float $lng): array
    {
        $ready = Vehicle::query()
            ->whereIn('status', [StatusValue::Active->value, StatusValue::Available->value])
            ->get(['id', 'capacity_kg']);

        $distanceKm = $lat !== null && $lng !== null && $fleet->latitude !== null && $fleet->longitude !== null
            ? round(Geo::metresBetween((float) $fleet->latitude, (float) $fleet->longitude, $lat, $lng) / 1000, 1)
            : null;

        return [
            'kind' => 'company',
            'id' => $fleet->getKey(),
            'name' => $fleet->name,
            'distance_km' => $distanceKm,
            'capacity_kg' => (int) $ready->max('capacity_kg'),
            'vehicles_ready' => $ready->count(),
            'address' => $fleet->address,
            'contact_phone' => $fleet->contact_phone,
            'trips_completed' => null,
        ];
    }
}
