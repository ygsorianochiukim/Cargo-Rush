<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\TruckSize;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin TruckSize */
class TruckSizeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'cbm' => $this->cbm,
            'status' => $this->status?->value,

            'vehicle_count' => $this->whenCounted('vehicles'),

            ...$this->stamps(),
        ];
    }
}
