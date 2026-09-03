<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Resources;

use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin FuelRecord
 */
class FuelRecordResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'litres' => $this->litres,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'odometer_km' => $this->odometer_km,
            'receipt_no' => $this->receipt_no,
            'logged_at' => $this->iso($this->logged_at),
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
