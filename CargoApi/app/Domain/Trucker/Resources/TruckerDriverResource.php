<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trucker\Models\TruckerDriver;
use Illuminate\Http\Request;

/** @mixin TruckerDriver */
class TruckerDriverResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trucker_id' => $this->trucker_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry?->toDateString(),
            'status' => $this->status->value,
            'email' => $this->whenLoaded('user', fn () => $this->user?->email),

            'employer' => [
                'kind' => 'trucker',
                'label' => $this->employerName(),
            ],

            ...$this->stamps(),
        ];
    }
}
