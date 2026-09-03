<?php

declare(strict_types=1);

namespace App\Domain\Driver\Resources;

use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Driver
 */
class DriverResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry?->toDateString(),
            // Derived, so the client does not have to re-implement the window.
            'licence_expiring' => $this->licenceExpiresWithin(),
            'violations' => $this->violations,
            'status' => $this->status->value,
            'trips_completed' => $this->trips_completed,
            'on_time_rate' => $this->on_time_rate,
            'user_id' => $this->user_id,

            ...$this->stamps(),
        ];
    }
}
