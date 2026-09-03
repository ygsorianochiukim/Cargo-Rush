<?php

declare(strict_types=1);

namespace App\Domain\Finance\Resources;

use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Truck
 */
class TruckResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            // Null is a real answer here — the client renders "Unassigned"
            // rather than dropping the unit.
            'plate' => $this->plate,
            'vehicle_id' => $this->vehicle_id,
            'position' => $this->position,
        ];
    }
}
