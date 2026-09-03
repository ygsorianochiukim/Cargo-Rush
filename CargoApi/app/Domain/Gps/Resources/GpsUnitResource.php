<?php

declare(strict_types=1);

namespace App\Domain\Gps\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/**
 * One live unit on the GPS Dashboard: a trip plus its latest ping, flattened
 * into the single row the map and the table both read.
 *
 * @mixin Trip
 */
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
            // A unit that has never pinged still belongs on the list — it is
            // dispatched. Saying so beats hiding it.
            'location' => $ping?->location ?? $this->origin,
            'speed_kph' => $ping?->speed_kph ?? 0,
            'heading' => $ping?->heading ?? 'N',
            'progress_pct' => $ping?->progress_pct ?? 0,
            'eta' => $this->iso($this->eta),
            'status' => $this->status->value,
            'updated_at' => $this->iso($ping?->recorded_at ?? $this->updated_at),
        ];
    }
}
