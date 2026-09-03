<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Resources;

use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin DispatchRecord
 */
class DispatchRecordResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'reference' => $this->trip?->reference,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'dispatched_at' => $this->iso($this->dispatched_at),
            'location' => $this->location,
            'arrived_at' => $this->iso($this->arrived_at),
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
