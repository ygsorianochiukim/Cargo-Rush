<?php

declare(strict_types=1);

namespace App\Domain\Driver\Resources;

use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;

/** @mixin Driver */
class DriverResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'name' => $this->name,
            'contact' => $this->contact,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry?->toDateString(),
            'licence_expiring' => $this->licenceExpiresWithin(),
            'violations' => $this->violations,
            'status' => $this->status->value,
            'trips_completed' => $this->trips_completed,
            'on_time_rate' => $this->on_time_rate,
            'user_id' => $this->user_id,
            'employee_id' => $this->employee?->id,
            'employee_no' => $this->employee?->employee_no,

            'employer' => [
                'kind' => 'fleet',
                'label' => app(Tenant::class)->company()?->name ?? 'Cargo Rush',
            ],

            ...$this->stamps(),
        ];
    }
}
