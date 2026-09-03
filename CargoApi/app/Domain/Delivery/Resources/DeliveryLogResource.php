<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Resources;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin DeliveryLog
 */
class DeliveryLogResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'reference' => $this->trip?->reference,
            'customer' => $this->trip?->customer?->name,
            'destination' => $this->trip?->destination,
            'driver_name' => $this->trip?->driver?->name,
            'helper_name' => $this->trip?->helper?->name,
            'delivered_at' => $this->iso($this->delivered_at),
            'pod_ref' => $this->pod_ref,
            // Derived, never stored: the disk and its public URL are
            // configuration, and a URL written into a row is wrong the moment
            // the install moves.
            'pod_image_url' => $this->podImageUrl(),
            'receiver_name' => $this->receiver_name,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
