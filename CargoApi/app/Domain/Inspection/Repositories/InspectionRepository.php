<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Repositories;

use App\Domain\Inspection\Models\Inspection;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class InspectionRepository extends Repository
{
    protected function model(): string
    {
        return Inspection::class;
    }

    public function query(): Builder
    {
        return Inspection::query()
            ->with(['vehicle:id,plate', 'driver:id,name', 'trip:id,reference'])
            // The id breaks a tie on the timestamp. Two checks in the same
            // second is what a re-check at the gate looks like, and "the
            // latest" has to mean the second one rather than whichever the
            // database happens to return — a ULID sorts by when it was minted.
            ->orderByDesc('inspected_at')
            ->orderByDesc('id');
    }

    /**
     * The fleet's inspection log is the fleet's: a trucker's driver checking
     * the trucker's truck is theirs, and is left out of every list. It still
     * clears its own run — `latestForTrip()` does not come through here.
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return parent::applyFilters($query, $filters)->whereNull('trucker_driver_id');
    }

    public function latestForTrip(string $tripId): ?Inspection
    {
        return $this->query()->where('trip_id', $tripId)->first();
    }

    /** The maintenance jobs assigned to a driver's current unit. */
    public function maintenanceForVehicle(string $vehicleId): Collection
    {
        return MaintenanceJob::query()
            ->with('vehicle:id,plate,odometer_km')
            ->where('vehicle_id', $vehicleId)
            ->orderBy('due_at')
            ->get();
    }
}
