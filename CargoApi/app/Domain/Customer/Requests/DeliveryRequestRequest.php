<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trip\DTO\TripData;

/**
 * A customer asking for a load to be picked up.
 *
 * Deliberately the smallest form in the system. Everything the office decides
 * is missing — driver, helper, unit, schedule, status — not because a customer
 * could not be shown those fields, but because they have no basis to fill them
 * in and a request that arrived pre-assigned would bypass the confirmation it
 * exists to ask for. `POST trips/{trip}/confirm` is where those four arrive.
 *
 * `customer_id` and `requested_by` are absent for a stronger reason: they are
 * the scope. Accepting either from the client would let one customer file a
 * request against another's account, which is the same hole the driver
 * endpoints avoid by taking no trip id. The controller stamps both from the
 * token.
 *
 * `preferred_at` is the customer's *wish*, mapped onto `scheduled_at`. The
 * desk can move it when confirming, which is the honest arrangement: a
 * customer asks for Tuesday morning, the fleet says whether Tuesday morning
 * is possible.
 */
class DeliveryRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Which haulier is being asked, from `GET portal/carriers`.
             *
             * Optional, and the omission is meaningful rather than lazy: a
             * customer the office put on the books, and every client written
             * before carriers could be chosen, means "the firm I already deal
             * with" — so an absent `carrier_id` files with the caller's home
             * carrier exactly as it always did.
             *
             * Not validated against `companies` here. `exists` would confirm
             * the id names a real company to anybody who can guess a ULID; the
             * controller resolves it through `CarrierDirectory`, which answers
             * 404 for a company that is neither offering to take work nor
             * already the customer's.
             */
            'carrier_id' => ['nullable', 'string', 'size:26'],

            'origin' => ['required', 'string', 'max:160'],
            // Optional coordinates, exactly as the office form has them: a
            // place name is enough to book against, but half a coordinate is
            // not a location, so each end requires its pair.
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'destination' => ['required', 'string', 'max:160'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
            'cargo' => ['required', 'string', 'max:255'],
            'weight_kg' => ['required', 'integer', 'min:1', 'max:60000'],
            'pieces' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'handling' => ['nullable', 'string', 'max:255'],
            // When they would like it collected. In the future, because a
            // pickup cannot be asked for in the past.
            'preferred_at' => ['required', 'date', 'after_or_equal:now'],

            /**
             * A partner trucker the customer picked off the hauler list.
             *
             * Optional, and its absence is the ordinary case: leaving it out
             * means "whoever the fleet sends", which is what picking Cargo Rush
             * on that screen does. Naming somebody makes the request an offer
             * to that one person. Left out, the request waits on the desk —
             * no trucker sees it, because there is no open board.
             *
             * Not validated with `exists` here. The row is tenant-scoped and
             * this rule would run before the offer is resolved, so a trucker at
             * another fleet would pass it and fail later with a worse message —
             * `PortalService::offerTo()` checks it and says what is actually
             * wrong.
             */
            'trucker_id' => ['nullable', 'string', 'max:26'],
        ];
    }

    public function messages(): array
    {
        return [
            'weight_kg.min' => 'A delivery has to weigh something. Enter the weight in kilograms.',
            'preferred_at.after_or_equal' => 'Choose a pickup time that has not already passed.',
            'carrier_id.size' => 'That is not a carrier we know. Pick one from the list.',
            'origin_lat.required_with' => 'A longitude needs its latitude.',
            'origin_lng.required_with' => 'A latitude needs its longitude.',
            'destination_lat.required_with' => 'A longitude needs its latitude.',
            'destination_lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    /** The haulier the customer picked, or null for their usual one. */
    public function carrierId(): ?string
    {
        $id = trim((string) $this->input('carrier_id', ''));

        return $id === '' ? null : $id;
    }

    /** The partner they picked, or null for "whoever the fleet sends". */
    public function truckerId(): ?string
    {
        $id = trim((string) $this->input('trucker_id', ''));

        return $id === '' ? null : $id;
    }

    /**
     * The request, as a trip.
     *
     * `preferred_at` becomes `scheduled_at`, and the two ids come from the
     * caller rather than the payload. The status is not set here — `request()`
     * forces `pending`, so there is one place that decides what a new request
     * looks like.
     */
    public function toData(string $customerId, int $userId): TripData
    {
        $validated = $this->validated();

        // None of the three is a column on `trips` as sent. `preferred_at`
        // becomes `scheduled_at` below; the carrier is answered for by which
        // company's tenancy the trip is written in; and the trucker is applied
        // by `PortalService::offerTo()` after the row exists, because naming
        // one is an offer with its own rules rather than a field to copy.
        // `TripData` would refuse an attribute the model has no field for.
        unset($validated['preferred_at'], $validated['carrier_id'], $validated['trucker_id']);

        return TripData::fromArray([
            ...$validated,
            'customer_id' => $customerId,
            'requested_by' => $userId,
            'scheduled_at' => $this->date('preferred_at')?->toIso8601String(),
        ]);
    }
}
