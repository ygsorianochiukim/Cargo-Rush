<?php

declare(strict_types=1);

namespace App\Domain\Incident\Resources;

use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Incident
 */
class IncidentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind,
            'place' => $this->place,
            'occurred_at' => $this->iso($this->occurred_at),
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'trip_id' => $this->trip_id,
            'trip_reference' => $this->trip?->reference,
            'notes' => $this->notes,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
