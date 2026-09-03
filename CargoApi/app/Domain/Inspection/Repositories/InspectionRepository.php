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
            ->orderByDesc('inspected_at');
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
