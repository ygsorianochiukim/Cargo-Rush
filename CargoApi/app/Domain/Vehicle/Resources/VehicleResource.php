<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * @mixin Vehicle
 */
class VehicleResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'model' => $this->model,
            'registration_no' => $this->registration_no,
            'capacity_kg' => $this->capacity_kg,
            'status' => $this->status->value,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'odometer_km' => $this->odometer_km,
            'next_service_km' => $this->next_service_km,
            // Negative means the interval has already passed.
            'km_to_service' => $this->kmToService(),

            ...$this->stamps(),
        ];
    }
}
