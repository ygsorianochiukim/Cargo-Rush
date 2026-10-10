<?php

declare(strict_types=1);

namespace App\Domain\Gps\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/** @mixin Trip */
class GpsUnitResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $ping = $this->latestPing;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'vehicle_plate' => $this->vehicle?->plate,
            'driver_name' => $this->driver?->name,
            'location' => $ping?->location ?? $this->origin,

            'lat' => $ping?->lat ?? ($this->origin_lat === null ? null : (float) $this->origin_lat),
            'lng' => $ping?->lng ?? ($this->origin_lng === null ? null : (float) $this->origin_lng),
            'has_fix' => $ping?->isPlotted() ?? false,

            'speed_kph' => $ping?->speed_kph ?? 0,
            'heading' => $ping?->heading ?? 'N',
            'progress_pct' => $ping?->progress_pct ?? 0,
            'eta' => $this->iso($this->eta),
            'status' => $this->status->value,
            'updated_at' => $this->iso($ping?->recorded_at ?? $this->updated_at),
        ];
    }
}
