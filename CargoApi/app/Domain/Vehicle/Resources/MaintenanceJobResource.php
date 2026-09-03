<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Http\Request;

/**
 * @mixin MaintenanceJob
 */
class MaintenanceJobResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'kind' => $this->kind,
            'due_at' => $this->due_at?->toDateString(),
            'odometer_km' => $this->vehicle?->odometer_km ?? 0,
            'next_service_km' => $this->next_service_km,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
