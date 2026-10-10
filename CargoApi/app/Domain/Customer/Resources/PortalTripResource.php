<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Resources\TripResource;
use Illuminate\Http\Request;

/** @mixin Trip */
class PortalTripResource extends TripResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'carrier_id' => $this->company_id,
            'carrier' => $this->company?->name,
        ];
    }

    protected function detailedInspection(): bool
    {
        return false;
    }
}
