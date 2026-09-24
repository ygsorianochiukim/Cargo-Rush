<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Services\LogoStore;
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
        // The units come with it because `canTakeWork()` reads them — a partner
        // with no truck free cannot take work, and reporting otherwise here
        // would have the app open on a board it is about to be refused from.
        $trucker = $this->trucker?->loadMissing('vehicles');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,

            /**
             * Which company this account signed in to.
             *
             * Filled in for every account but one, and both shells name it in
             * the header so nobody has to wonder whose system they are looking
             * at. A driver drives for a company and a customer is a customer
             * *of* one; the exception is a shipper who has just signed
             * themselves up and not yet chosen a haulier, who is a customer of
             * nobody until their first request. Null there, and filled in from
             * that request onwards, so a client reading it for a header needs a
             * fallback rather than an assumption.
             *
             * The clients do not use it to decide what to fetch. Scoping
             * happens server-side, on every query, from the same account this
             * was read off; a client that sent an id back would be ignored.
             */
            'company_id' => $this->company_id,
            'company_name' => $this->company?->name,
            'company_code' => $this->company?->code,
            // The mark the shells draw beside the wordmark. Null means render
            // the company's initials — the same fallback the user chip already
            // makes for an account with no avatar, so neither client needs a
            // second idea about missing images.
            'company_logo_url' => app(LogoStore::class)->url($this->company?->logo_path),

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
            // Null while a self-registered shipper has yet to pick a haulier —
            // there is no `customers` row until there is a carrier to own it —
            // and null on an account nobody ever linked to a firm, which the
            // portal endpoints still report as a 404. `chooses_carrier` tells
            // the two apart.
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,

            /**
             * May this customer choose who carries their load?
             *
             * True only for a shipper who signed themselves up on the platform.
             * The app hides the carrier list entirely when it is false, because
             * an account the office created belongs to that haulier — and a
             * screen offering a choice the API will refuse is worse than no
             * screen at all.
             */
            'chooses_carrier' => $this->choosesCarrier(),

            /**
             * Where this firm's loads go out from, if a haulier keeps an
             * address for them.
             *
             * Not asked for at sign-up any more, and usually null for a
             * self-registered shipper: where a load is going out from is
             * answered per request, on the request form, from the handset's own
             * position or the map. What fills this in is an office writing an
             * address onto its own `customers` row for a firm it collects from
             * at the same door every week — and when it is there the request
             * form starts "pick up from" with it, and the carrier list is
             * measured from it when the handset has no position of its own.
             */
            'customer_address' => $this->customer?->address,
            'customer_lat' => $this->customer?->latitude,
            'customer_lng' => $this->customer?->longitude,

            /**
             * Present only for a partner trucker — the third of the three
             * handset identities, beside `driver_id` and `customer_id`.
             *
             * `trucker_status` is the one field here the app genuinely branches
             * on. A registration lands `pending` and the job board stays empty
             * until somebody at the fleet approves it, so an app reading only
             * the empty board would open on a screen that looks broken. This is
             * what lets it say "we are checking your licence" instead.
             *
             * `trucker_online` is the partner's own switch and is theirs to
             * flip; it says nothing about whether they are approved. Both are
             * reported because collapsing them would leave the app unable to
             * tell somebody waiting on the office from somebody who is simply
             * off duty.
             */
            'trucker_id' => $trucker?->id,
            'trucker_status' => $trucker?->status->value,
            'trucker_online' => $trucker === null ? null : $trucker->is_online,
            'trucker_can_take_work' => $trucker === null ? null : $trucker->canTakeWork(),
            // What their runs split at, so the app can state the rate on the
            // job board without a second call.
            'commission_bp' => $trucker?->commissionRateBp(),
        ];
    }
}
