<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin PricingZone
 */
class PricingZoneResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'aliases' => $this->aliases ?? [],
            'diesel_baseline_cents' => $this->diesel_baseline_cents,
            'position' => $this->position,
            'status' => $this->status->value,
            'notes' => $this->notes,
            // Always present: the zone editor has nothing to render without
            // the card, and `PricingZoneRepository` eager-loads it for exactly
            // that reason.
            'brackets' => PricingBracketResource::collection($this->brackets),
            'bracket_count' => $this->brackets->count(),

            ...$this->stamps(),
        ];
    }
}
