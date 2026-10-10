<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin PricingBracket */
class PricingBracketResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'zone_id' => $this->zone_id,
            'truck_category_id' => $this->truck_category_id,
            'truck_category' => $this->whenLoaded(
                'truckCategory',
                fn () => $this->truckCategory === null
                    ? null
                    : new TruckCategoryResource($this->truckCategory),
            ),
            'label' => $this->label,
            'min_km' => $this->min_km,
            'max_km' => $this->max_km,
            'range' => $this->range(),
            'base_cents' => $this->base_cents,
            'per_km_cents' => $this->per_km_cents,
            'per_kg_cents' => $this->per_kg_cents,
            'minimum_cents' => $this->minimum_cents,
            'diesel_step_cents' => $this->diesel_step_cents,
            'position' => $this->position,

            ...$this->stamps(),
        ];
    }
}
