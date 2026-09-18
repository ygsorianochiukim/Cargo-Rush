<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin PricingBracket
 */
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
            // Null on a banded line, which borrows its zone's kilometres. The
            // nulls are sent rather than hidden, because an editor has to know
            // which rows it may offer a distance field for.
            'min_km' => $this->min_km,
            'max_km' => $this->max_km,
            // Derived here rather than in each client, so the web and the
            // handset describe the same line with the same words.
            'range' => $this->range(),
            'base_cents' => $this->base_cents,
            'per_km_cents' => $this->per_km_cents,
            'per_kg_cents' => $this->per_kg_cents,
            'minimum_cents' => $this->minimum_cents,
            // Pesos per ₱1/L of diesel above the baseline, in centavos — the
            // rightmost column of a printed subsidy table.
            'diesel_step_cents' => $this->diesel_step_cents,
            'position' => $this->position,

            ...$this->stamps(),
        ];
    }
}
