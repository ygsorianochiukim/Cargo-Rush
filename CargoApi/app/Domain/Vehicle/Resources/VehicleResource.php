<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Http\Request;

/** @mixin Vehicle */
class VehicleResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'model' => $this->model,
            'registration_no' => $this->registration_no,
            'capacity_kg' => $this->capacity_kg,
            'status' => $this->status->value,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'odometer_km' => $this->odometer_km,
            'next_service_km' => $this->next_service_km,
            'km_to_service' => $this->kmToService(),

            'arrangement' => $this->terms()->value,
            'arrangement_label' => $this->terms()->label(),
            'hired' => $this->terms()->isHired(),
            'wheels' => $this->wheels,
            'truck_category_id' => $this->truck_category_id,

            'owner_name' => $this->owner_name,
            'owner_contact' => $this->owner_contact,
            'rent_cents' => $this->rent_cents,

            'share_bp' => $this->terms()->sharesRevenue() ? $this->shareRateBp() : null,
            'shares_revenue' => $this->sharesRevenue(),
            'owner_trucker_id' => $this->owner_trucker_id,
            'owner_trucker_name' => $this->whenLoaded(
                'ownerPartner',
                fn () => $this->ownerPartner?->name,
            ),

            ...$this->stamps(),
        ];
    }
}
