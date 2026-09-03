<?php

declare(strict_types=1);

namespace App\Domain\Trip\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/**
 * The run a driver is on right now, as the mobile Dashboard shows it.
 *
 * A trip plus its latest position. The position is on this resource and not on
 * `TripResource` on purpose: a list of trips has no business loading a ping
 * apiece, and only this one endpoint needs it.
 *
 * @mixin Trip
 */
class CurrentTripResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $ping = $this->latestPing;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'cargo' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'customer' => $this->customer?->name,
            // The plate is what the driver reads; the id is what the daily log
            // matches its ledger sheet on, because a plate gets corrected and
            // reformatted and a foreign key does not.
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'helper_name' => $this->helper?->name,
            'status' => $this->status->value,
            'scheduled_at' => $this->iso($this->scheduled_at),
            'eta' => $this->iso($this->eta),

            // The handset needs both ends to work out how far along it is
            // without asking the server on every reading — which, at one
            // reading a minute for ten hours, it should not have to.
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
            'distance_total_m' => $this->distance_total_m,
            'mapped' => $this->isMapped(),

            // Zero and the origin are the honest answers before the first ping:
            // the unit is dispatched and has not reported moving yet.
            'progress_pct' => $ping?->progress_pct ?? 0,
            'current_location' => $ping?->location ?? $this->origin,
            'reported_at' => $this->iso($ping?->recorded_at),
        ];
    }
}
