<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trucker\Models\TruckerVehicle;
use Illuminate\Http\Request;

/**
 * @mixin TruckerVehicle
 */
class TruckerVehicleResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'model' => $this->model,
            'capacity_kg' => $this->capacity_kg,
            'truck_category_id' => $this->truck_category_id,
            // The word rather than the id, so a card can say "Freezer" without
            // a second call. Null when the partner did not say what kind it is,
            // which is allowed — an unstated requirement is not a requirement.
            'truck_category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
