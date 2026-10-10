<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Services\LogoStore;
use Illuminate\Http\Request;

/** @mixin User */
class MeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $driver = $this->driver?->loadMissing('vehicle:id,driver_id,plate');
        $trucker = $this->trucker?->loadMissing('vehicles');
        $crew = $this->role === Role::TruckerDriver->value
            ? $this->truckerDriver?->loadMissing('trucker')
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,

            'company_id' => $this->company_id,
            'company_name' => $this->company?->name,
            'company_code' => $this->company?->code,
            'company_logo_url' => app(LogoStore::class)->url($this->company?->logo_path),

            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            'avatar_url' => $this->avatar_url,
            'permissions' => $this->permissions(),

            'driver_id' => $driver?->id,
            'licence_no' => $driver?->licence_no,
            'licence_expiry' => $driver?->licence_expiry?->toDateString(),
            'available' => $driver === null ? null : $driver->status->value !== 'inactive',

            'vehicle_id' => $driver?->vehicle?->id,
            'vehicle_plate' => $driver?->vehicle?->plate,

            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,

            'chooses_carrier' => $this->choosesCarrier(),

            'customer_address' => $this->customer?->address,
            'customer_lat' => $this->customer?->latitude,
            'customer_lng' => $this->customer?->longitude,

            'trucker_id' => $trucker?->id,
            'trucker_status' => $trucker?->status->value,
            'trucker_online' => $trucker === null ? null : $trucker->is_online,
            'trucker_can_take_work' => $trucker === null ? null : $trucker->canTakeWork(),
            'commission_bp' => $trucker?->commissionRateBp(),
            'trucker_business_name' => $trucker?->business_name,

            'trucker_driver_id' => $crew?->id,
            'crew_employer' => $crew?->employerName(),
            'crew_may_drive' => $crew === null ? null : $crew->mayDrive(),
        ];
    }
}
