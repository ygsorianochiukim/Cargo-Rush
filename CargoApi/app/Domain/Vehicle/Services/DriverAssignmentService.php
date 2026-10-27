<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Services;

use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleDriverAssignment;
use Carbon\CarbonInterface;

class DriverAssignmentService
{
    public function sync(Vehicle $vehicle, ?CarbonInterface $on = null): void
    {
        $today = ($on ?? now())->copy()->startOfDay();

        $open = VehicleDriverAssignment::query()
            ->open()
            ->where('vehicle_id', $vehicle->getKey())
            ->orderByDesc('from_date')
            ->first();

        if ($open !== null && $open->driver_id === $vehicle->driver_id) {
            return;
        }

        if ($open !== null) {
            $this->close($open, $today);
        }

        if ($vehicle->driver_id === null) {
            return;
        }

        VehicleDriverAssignment::create([
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $vehicle->driver_id,
            'from_date' => $today->toDateString(),
        ]);
    }

    public function release(Vehicle $vehicle, ?CarbonInterface $on = null): void
    {
        $today = ($on ?? now())->copy()->startOfDay();

        VehicleDriverAssignment::query()
            ->open()
            ->where('vehicle_id', $vehicle->getKey())
            ->get()
            ->each(fn (VehicleDriverAssignment $open) => $this->close($open, $today));
    }

    private function close(VehicleDriverAssignment $open, CarbonInterface $today): void
    {
        $lastDay = $today->copy()->subDay();

        if ($lastDay->lt($open->from_date)) {
            $open->delete();

            return;
        }

        $open->update(['to_date' => $lastDay->toDateString()]);
    }
}
