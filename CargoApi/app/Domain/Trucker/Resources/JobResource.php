<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/** @mixin Trip */
class JobResource extends ApiResource
{
    public function __construct(
        Trip $resource,
        private readonly int $rateBp = 0,
        private readonly int $takeCents = 0,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'origin' => $this->origin,
            'destination' => $this->destination,
            'pickup_place' => $this->pickup_place,
            'dropoff_place' => $this->dropoff_place,
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,

            'cargo' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'pieces' => $this->pieces,
            'handling' => $this->handling,
            'truck_category' => $this->whenLoaded('truckCategory', fn () => $this->truckCategory?->name),
            'truck_size' => $this->whenLoaded('truckSize', fn () => $this->truckSize?->name),

            'distance_total_m' => $this->distance_total_m,
            'distance_from_m' => $this->distance_from_m ?? null,

            'scheduled_at' => $this->iso($this->scheduled_at),
            'status' => $this->status->value,

            'price_cents' => $this->price_cents,
            'commission_bp' => $this->rateBp,
            'your_take_cents' => $this->takeCents,
            'currency' => $this->currency,

            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),

            'created_at' => $this->iso($this->created_at),
        ];
    }
}
