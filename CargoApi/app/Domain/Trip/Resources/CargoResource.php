<?php

declare(strict_types=1);

namespace App\Domain\Trip\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/**
 * Cargo Details, as the driver app reads it — DESIGN.md section 5.2.
 *
 * A trip plus its dispatch and delivery records, flattened into the one object
 * that screen shows. Same reasoning as `GpsUnitResource`: the shape a client
 * renders is the API's to define, and stitching three records together in the
 * handset would put a join in the wrong place.
 *
 * @mixin Trip
 */
class CargoResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $dispatch = $this->dispatchRecord;
        $delivery = $this->deliveryLog;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'description' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'pieces' => $this->pieces,
            'handling' => $this->handling,
            'customer' => $this->customer?->name,

            'pickup_place' => $this->pickup_place ?? $this->origin,
            // Before it rolls, the plan is the best answer there is.
            'pickup_at' => $this->iso($dispatch?->dispatched_at ?? $this->scheduled_at),
            'dropoff_place' => $this->dropoff_place ?? $this->destination,
            'dropoff_at' => $this->iso($delivery?->delivered_at),

            'dispatched_at' => $this->iso($dispatch?->dispatched_at),
            'arrived_at' => $this->iso($dispatch?->arrived_at),
            'eta' => $this->iso($this->eta),
            'status' => $this->status->value,
        ];
    }
}
