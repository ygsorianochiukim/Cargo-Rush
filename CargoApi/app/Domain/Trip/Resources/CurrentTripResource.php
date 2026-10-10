<?php

declare(strict_types=1);

namespace App\Domain\Trip\Resources;

use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/** @mixin Trip */
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
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate ?? $this->truckerVehicle?->plate,
            'helper_names' => $this->helpers->pluck('name')->all(),
            'status' => $this->status->value,
            'scheduled_at' => $this->iso($this->scheduled_at),
            'eta' => $this->iso($this->eta),

            'inspection' => app(InspectionService::class)->summaryFor($this->resource),

            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
            'distance_total_m' => $this->distance_total_m,
            'mapped' => $this->isMapped(),

            'progress_pct' => $ping?->progress_pct ?? 0,
            'current_location' => $ping?->location ?? $this->origin,
            'reported_at' => $this->iso($ping?->recorded_at),
        ];
    }
}
