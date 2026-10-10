<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin PricingZone */
class PricingZoneResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'min_km' => $this->min_km,
            'max_km' => $this->max_km,
            'band' => $this->band(),
            'diesel_baseline_cents' => $this->diesel_baseline_cents,
            'position' => $this->position,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'brackets' => PricingBracketResource::collection($this->brackets),
            'bracket_count' => $this->brackets->count(),

            ...$this->stamps(),
        ];
    }
}
