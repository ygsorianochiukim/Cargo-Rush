<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin TruckCategory */
class TruckCategoryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'cbm' => $this->cbm,
            'position' => $this->position,
            'status' => $this->status?->value,

            'bracket_count' => $this->whenCounted('brackets'),
            'vehicle_count' => $this->whenCounted('vehicles'),

            ...$this->stamps(),
        ];
    }
}
