<?php

declare(strict_types=1);

namespace App\Domain\Trip\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/** @mixin Trip */
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
