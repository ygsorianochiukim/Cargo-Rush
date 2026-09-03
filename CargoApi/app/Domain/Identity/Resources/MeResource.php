<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me` — DESIGN.md section 7.2. This drives the sidebar user chip
 * on web and the Profile screen on mobile.
 *
 * @mixin User
 */
class MeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $driver = $this->driver?->loadMissing('vehicle:id,driver_id,plate');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // The machine key and the display string, separately: the client
            // uppercases the label and never parses the key for words.
            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            // Null means the client renders initials.
            'avatar_url' => $this->avatar_url,
            'permissions' => $this->permissions(),

            // Present only for a driver. The back office has no licence.
            'driver_id' => $driver?->id,
            'licence_no' => $driver?->licence_no,
            'licence_expiry' => $driver?->licence_expiry?->toDateString(),
            'available' => $driver === null ? null : $driver->status->value !== 'inactive',

            // The unit they currently hold the keys to. The Inspect screen
            // needs it before there is a trip to read one from — a pre-trip
            // check happens at the vehicle, not on the road.
            'vehicle_id' => $driver?->vehicle?->id,
            'vehicle_plate' => $driver?->vehicle?->plate,

            // Present only for a customer, and the mirror of the driver pair
            // above: the app decides which home screen to open on from `role`,
            // and this is the record everything on that screen is scoped to.
            // Null on a customer account means one nobody linked to a firm,
            // which the portal endpoints report as a 404 rather than an empty
            // page — so the client can say so before asking.
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,
        ];
    }
}
