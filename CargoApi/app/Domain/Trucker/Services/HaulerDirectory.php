<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Who could carry this load — the fleet, and the truckers near it.
 *
 * The customer's side of the partner feature, and the mirror image of the
 * trucker's job board: there, a partner sees the loads near them; here, a
 * customer sees the partners near their load.
 *
 * ## Two kinds of answer on one list
 *
 * **The fleet** is always on it, first, and is never filtered by distance. It
 * has a yard, a roster and units it can send, so it is the answer for a load
 * across town and for one two provinces away alike. A customer who sees an
 * empty list because nobody happened to be nearby would conclude the app
 * cannot help them, when the business plainly can.
 *
 * **The truckers** are filtered by distance, and tightly. One man with one
 * truck 150 km away is not available this afternoon whatever the radius says,
 * and listing him is offering something that will not arrive.
 *
 * ## What a customer is allowed to see about a partner
 *
 * A name, what their truck can carry, how far away they are and how many runs
 * they have completed. That is what somebody hiring a haulier needs and it is
 * the most that should ever leave this class — no phone number until there is
 * a booking, no licence, no wallet, no other customers. A directory is not a
 * reason to publish a contractor's papers.
 *
 * ## Why this is not `CarrierDirectory`
 *
 * That one lists *companies* across the whole platform and is deliberately
 * cross-tenant — it is how a shipper finds a haulier at all. This lists one
 * company's own contractors and never leaves its tenancy. Two lists that look
 * similar on screen and share no rule worth unifying.
 */
class HaulerDirectory
{
    /**
     * The list, with the fleet first and the nearest partner after it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function near(Company $fleet, ?float $lat = null, ?float $lng = null): Collection
    {
        $radiusKm = (float) config('cargo.truckers.customer_radius_km', 60);

        $truckers = Trucker::query()
            ->with('vehicles')
            ->takingWork()
            ->get()
            ->map(fn (Trucker $trucker): ?array => $this->listing($trucker, $lat, $lng, $radiusKm))
            ->filter()
            ->sortBy('distance_km')
            ->values();

        return collect([$this->fleetListing($fleet, $lat, $lng)])->concat($truckers);
    }

    /**
     * One partner, or null if they are not a usable answer.
     *
     * Three ways to be dropped, and each is a real case rather than defensive
     * padding: no truck free to put under a load, no recent position to measure
     * from, and too far away to turn up. The second is the one worth stating —
     * a partner whose last pin is from Tuesday is not "near" anywhere, and
     * treating a stale position as current is how a customer ends up waiting
     * for somebody in another province.
     *
     * @return array<string, mixed>|null
     */
    private function listing(Trucker $trucker, ?float $lat, ?float $lng, float $radiusKm): ?array
    {
        $unit = $trucker->activeVehicle();

        if ($unit === null) {
            return null;
        }

        $distanceKm = null;

        if ($lat !== null && $lng !== null) {
            $metres = $trucker->metresTo($lat, $lng);

            // No usable pin, or beyond the radius. Either way they are not an
            // answer to "who can carry this, here".
            if ($metres === null || $metres > $radiusKm * 1000) {
                return null;
            }

            $distanceKm = round($metres / 1000, 1);
        }

        return [
            'kind' => 'trucker',
            'id' => $trucker->getKey(),
            'name' => $trucker->name,
            // No phone number. A customer who has not booked anything has no
            // business ringing a contractor directly, and the desk is what a
            // question before booking goes to.
            'distance_km' => $distanceKm,
            'capacity_kg' => $unit->capacity_kg,
            'vehicle' => $unit->model,
            'plate' => $unit->plate,
            'truck_category' => $unit->category?->name,
            // The only reputation signal there is. Honest and small on purpose:
            // a rating nobody has collected would be a number made up to look
            // like one.
            'trips_completed' => $trucker->trips_completed,
        ];
    }

    /**
     * The fleet's own entry.
     *
     * Counted the way the carrier directory counts a haulier — units not in the
     * workshop, and what they carry between them — so a customer comparing the
     * fleet against one man with one truck is comparing like with like.
     *
     * @return array<string, mixed>
     */
    private function fleetListing(Company $fleet, ?float $lat, ?float $lng): array
    {
        /**
         * Units not in the workshop and not retired — `active` as well as
         * `available`.
         *
         * The same pair `CarrierDirectory` counts, and matching it is the whole
         * point: a customer comparing the fleet against one man with one truck
         * has to be comparing like with like, and two directories that counted
         * a fleet differently would show two different firms.
         *
         * `available` alone was wrong and visibly so: a unit out on a run is
         * `active`, so a working fleet reported "0 trucks ready" precisely when
         * it was busiest.
         */
        $ready = Vehicle::query()
            ->whereIn('status', [StatusValue::Active->value, StatusValue::Available->value])
            ->get(['id', 'capacity_kg']);

        $distanceKm = $lat !== null && $lng !== null && $fleet->latitude !== null && $fleet->longitude !== null
            ? round(Geo::metresBetween((float) $fleet->latitude, (float) $fleet->longitude, $lat, $lng) / 1000, 1)
            : null;

        return [
            'kind' => 'company',
            'id' => $fleet->getKey(),
            'name' => $fleet->name,
            'distance_km' => $distanceKm,
            // The largest thing they could send, which is what decides whether
            // the fleet can take a load at all.
            'capacity_kg' => (int) $ready->max('capacity_kg'),
            'vehicles_ready' => $ready->count(),
            'address' => $fleet->address,
            'contact_phone' => $fleet->contact_phone,
            'trips_completed' => null,
        ];
    }
}
