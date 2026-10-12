<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Repositories\VehicleRepository;
use Illuminate\Database\Eloquent\Model;

class VehicleService extends CrudService
{
    private const SERVICE_WARNING_KM = 500;

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly NotificationService $notifications,
        private readonly TruckRentService $rent,
    ) {}

    protected function repository(): Repository
    {
        return $this->vehicles;
    }

    public function create(Data $data): Model
    {
        $vehicle = parent::create($data);

        if ($vehicle instanceof Vehicle) {
            $this->sizeFromWheels($vehicle);
            $this->rent->chargeDueFor($vehicle, now());
        }

        return $vehicle;
    }

    public function update(Model $model, Data $data): Model
    {
        $vehicle = parent::update($model, $data);

        if ($vehicle instanceof Vehicle) {
            $this->sizeFromWheels($vehicle);
            $this->rent->chargeDueFor($vehicle, now());
        }

        return $vehicle;
    }

    private function sizeFromWheels(Vehicle $vehicle): void
    {
        if ($vehicle->truck_category_id !== null) {
            return;
        }

        $size = TruckCategory::sizedForWheels($vehicle->wheels);

        if ($size !== null) {
            $vehicle->update(['truck_category_id' => $size->id]);
        }
    }

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
