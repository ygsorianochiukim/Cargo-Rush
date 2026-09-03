<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Resources;

use App\Domain\Inspection\Models\Inspection;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Inspection
 */
class InspectionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'trip_reference' => $this->trip?->reference,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'results' => $this->results,
            // The API's call, not the client's.
            'good_to_go' => $this->good_to_go,
            'failures' => $this->failures(),
            'notes' => $this->notes,
            'inspected_at' => $this->iso($this->inspected_at),

            ...$this->stamps(),
        ];
    }
}
