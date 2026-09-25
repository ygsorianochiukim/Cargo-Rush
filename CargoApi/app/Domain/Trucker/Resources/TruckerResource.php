<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Http\Request;

/**
 * A partner, as the office's roster and the partner's own profile both read
 * them.
 *
 * @mixin Trucker
 */
class TruckerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry?->toDateString(),

            /**
             * The two flags, separately, because they mean different things and
             * no client should collapse them.
             *
             * `status` is the office's decision and `is_online` is the
             * partner's; a screen that showed one composite "available" would
             * be unable to say whether somebody is waiting on an approval or
             * simply asleep, which is the difference between a support call and
             * nothing at all.
             */
            'status' => $this->status->value,
            'is_online' => $this->is_online,
            // Derived, so neither client re-implements the two-part rule.
            'can_take_work' => $this->canTakeWork(),

            /**
             * The rate as it actually applies, and whether it is theirs.
             *
             * Both, because "12%" and "12% because that is the house rate"
             * answer different questions — and a desk looking at a partner it
             * negotiated with needs to see that the number is a negotiated one
             * rather than a default that happens to match.
             */
            'commission_bp' => $this->commissionRateBp(),

            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'located_at' => $this->iso($this->located_at),
            // Whether that pin is recent enough to act on. A position with no
            // freshness beside it is indistinguishable from one from last week.
            'position_fresh' => $this->hasFreshPosition(),

            'trips_completed' => $this->trips_completed,
            'user_id' => $this->user_id,

            'vehicles' => TruckerVehicleResource::collection($this->whenLoaded('vehicles')),

            ...$this->stamps(),
        ];
    }
}
