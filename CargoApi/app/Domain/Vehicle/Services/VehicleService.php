<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Repositories\VehicleRepository;

class VehicleService extends CrudService
{
    /** How close to the service interval counts as "due". */
    private const SERVICE_WARNING_KM = 500;

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly NotificationService $notifications,
    ) {}

    protected function repository(): Repository
    {
        return $this->vehicles;
    }

    /** Taking a unit off the road frees whoever was driving it. */
    public function setStatus(Vehicle $vehicle, StatusValue $status): Vehicle
    {
        $vehicle->update([
            'status' => $status->value,
            'driver_id' => in_array($status, [StatusValue::Maintenance, StatusValue::Inactive], true)
                ? null
                : $vehicle->driver_id,
        ]);

        return $vehicle->refresh();
    }

    /**
     * Warn on units close to, or past, their service interval. Scheduled work.
     */
    public function flagServiceDue(): int
    {
        $due = $this->vehicles->query()
            ->whereColumn('odometer_km', '>=', 'next_service_km')
            ->orWhereRaw('next_service_km - odometer_km <= ?', [self::SERVICE_WARNING_KM])
            ->get();

        foreach ($due as $vehicle) {
            $left = $vehicle->kmToService();

            $this->notifications->push(
                icon: 'fleet',
                title: "{$vehicle->plate} service due",
                detail: $left <= 0
                    ? 'Past the scheduled interval by '.abs($left).' km'
                    : "Only {$left} km left before the scheduled interval",
                tone: $left <= 0 ? Tone::Danger : Tone::Warning,
            );
        }

        return $due->count();
    }
}
