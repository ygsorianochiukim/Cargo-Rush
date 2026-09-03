<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\DieselPrice;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin DieselPrice
 */
class DieselPriceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // A date, not a timestamp — so the day is not shifted by an hour
            // of timezone on the way to a client.
            'effective_on' => $this->effective_on?->toDateString(),
            'price_per_litre_cents' => $this->price_per_litre_cents,
            'currency' => $this->currency,
            'source' => $this->source,
            'recorded_by' => $this->recorded_by,

            ...$this->stamps(),
        ];
    }
}
