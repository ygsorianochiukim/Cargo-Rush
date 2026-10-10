<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Http\Request;

/** @mixin Trucker */
class TruckerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'phone' => $this->phone,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry?->toDateString(),

            'status' => $this->status->value,
            'is_online' => $this->is_online,
            'can_take_work' => $this->canTakeWork(),

            'commission_bp' => $this->commissionRateBp(),

            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'located_at' => $this->iso($this->located_at),
            'position_fresh' => $this->hasFreshPosition(),

            'trips_completed' => $this->trips_completed,
            'user_id' => $this->user_id,

            'vehicles' => TruckerVehicleResource::collection($this->whenLoaded('vehicles')),
            'drivers' => TruckerDriverResource::collection($this->whenLoaded('drivers')),

            ...$this->stamps(),
        ];
    }
}
