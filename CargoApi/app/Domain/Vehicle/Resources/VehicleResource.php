<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * @mixin Vehicle
 */
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
            // Negative means the interval has already passed.
            'km_to_service' => $this->kmToService(),

            /**
             * On what terms this truck runs, and who is paid for it.
             *
             * `arrangement` is the one field a screen branches on; the rest
             * are the terms that go with it and are null for the fleet's own
             * units, which is most of them.
             */
            'arrangement' => $this->terms()->value,
            'arrangement_label' => $this->terms()->label(),
            'hired' => $this->terms()->isHired(),
            'wheels' => $this->wheels,

            'owner_name' => $this->owner_name,
            'owner_contact' => $this->owner_contact,
            'rent_cents' => $this->rent_cents,

            /**
             * The rate as it actually applies, and whether the truck is
             * properly set up to pay it.
             *
             * `shares_revenue` is false for a unit marked as a share
             * arrangement with nobody named to receive it — a half-configured
             * truck that would haul all month and credit nothing. The fleet
             * screen shows the gap rather than letting it surface as an
             * owner asking where their money went.
             */
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
