<?php

declare(strict_types=1);

namespace App\Domain\Customer\Controllers;

use App\Domain\Customer\Requests\DeliveryRequestRequest;
use App\Domain\Customer\Resources\PortalInvoiceResource;
use App\Domain\Customer\Resources\PortalTripResource;
use App\Domain\Customer\Services\PortalService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\NearbyCarriersRequest;
use App\Domain\Tenancy\Resources\CarrierResource;
use App\Domain\Trucker\Services\HaulerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own screens — what `cargoApp` shows when the account signing
 * in is a customer rather than a driver.
 *
 * The exact counterpart of `DriverTripController`, and separate from
 * `TripController` for the same reason: every endpoint here is scoped to the
 * accounts behind the caller's login and carries no id that could be changed
 * into somebody else's. The office reads the same trips through the module
 * controller, with the permissions that come with it.
 *
 * A **self-registered** shipper may deal with several hauliers — they choose one
 * from `GET portal/carriers` when they file a request — so the reads here total
 * across those carriers and each row says which carrier it belongs to. The
 * scoping itself is unchanged and is not weakened by it: `PortalService` reads
 * one carrier's books at a time, inside that carrier's tenancy.
 *
 * A customer the **office created** never has more than one, and is shown no
 * other: the list and a named carrier are both refused for them, which is why
 * their portal behaves exactly as it did before any of this existed.
 */
class PortalController extends ApiController
{
    public function __construct(
        private readonly PortalService $portal,
        // The fleet's own contractors, near the load. A different list from
        // `carriers` above and deliberately a different class — see
        // `HaulerDirectory`.
        private readonly HaulerDirectory $haulers,
    ) {}

    /**
     * `GET portal/carriers` — who could pick this up.
     *
     * The screen a self-registered shipper's request starts on. Coordinates are
     * optional: with them the list is nearest first, without them it is
     * alphabetical, because a customer who declined the location prompt should
     * still be able to find the haulier they already use.
     *
     * A 403 for an account the office created — they are that haulier's
     * customer, and this list is not theirs to browse.
     *
     * A raw payload rather than a resource collection with pagination — the
     * list is capped at a couple of dozen cards by the directory itself, and a
     * page of carriers is not a thing a person pages through.
     */
    public function carriers(NearbyCarriersRequest $request): JsonResponse
    {
        $listings = $this->portal->carriers(
            $this->user($request),
            $request->point(),
            $request->radiusKm(),
            $request->search(),
        );

        return $this->collection(CarrierResource::collection($listings), $listings);
    }

    /**
     * Who could carry this load — the fleet, and the truckers near it.
     *
     * Distinct from `carriers()` above, which lists *companies* across the
     * whole platform and is how a shipper finds a haulier at all. This is
     * inside one haulier: its own fleet, plus the vetted contractors near the
     * load. The fleet is always on the list and never filtered by distance; a
     * partner is only there if they are genuinely close enough to turn up.
     *
     * Before `requests/{tripId}` in the route file, so "haulers" is never read
     * as a trip id.
     */
    public function haulers(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $fleet = $this->portal->carrier($user, null);

        $listings = $this->haulers->near(
            $fleet,
            $request->has('lat') ? (float) $request->input('lat') : null,
            $request->has('lng') ? (float) $request->input('lng') : null,
        );

        return $this->payload($listings->all(), ['fleet_id' => $fleet->getKey()]);
    }

    /** The counts and the two money figures the customer home screen leads with. */
    public function summary(Request $request): JsonResponse
    {
        return $this->payload($this->portal->summary($this->user($request)));
    }

    /** Everything this customer has asked for, newest first. */
    public function index(Request $request): JsonResponse
    {
        $trips = $this->portal->requests($this->user($request), $this->filters($request));

        return $this->collection(PortalTripResource::collection($trips), $trips);
    }

    /**
     * One of their own deliveries.
     *
     * The id is taken as a string rather than route-bound — the route says
     * `{tripId}` for exactly that reason — because binding resolves under the
     * caller's own company and would 404 a delivery the customer can see on
     * their own list at another carrier. `PortalService`
     * asks each of their carriers in turn and checks ownership on the row it
     * finds — another firm's is a 404, not a 403, because telling somebody a
     * trip exists but is not theirs confirms the trip exists.
     */
    public function show(Request $request, string $tripId): JsonResponse
    {
        return $this->item(new PortalTripResource($this->portal->request($this->user($request), $tripId)));
    }

    /**
     * Ask for a pickup.
     *
     * `carrier_id` is the customer's pick from the carrier list; without one
     * the request goes to the haulier whose books they are already on, which is
     * what every client written before the list sends. Filing with a carrier
     * for the first time opens an account there — see `ShipperAccounts::open()`
     * — so the far end sees an ordinary customer and an ordinary pending trip.
     *
     * Comes back as the trip it created, `pending`, with its reference, the
     * price it was quoted at and the carrier that now holds it — so the
     * customer leaves the form knowing what to quote on the phone, what it will
     * cost, and who to ring, instead of waiting to be told.
     */
    public function store(DeliveryRequestRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $carrier = $this->portal->carrier($user, $request->carrierId());
        $account = $this->portal->accountFor($user, $carrier);

        $trip = $this->portal->submit(
            $account,
            $request->toData($account->id, (int) $user->id),
            // Naming a partner turns the request into an offer held for them
            // alone. Null — the ordinary case — leaves it with the office to place.
            $request->truckerId(),
        );

        return $this->item(new PortalTripResource($trip), status: 201);
    }

    /** Their receivables — what is owed, and what has been settled. */
    public function invoices(Request $request): JsonResponse
    {
        $invoices = $this->portal->invoices($this->user($request));

        return $this->collection(PortalInvoiceResource::collection($invoices), $invoices);
    }

    /**
     * The account behind the request.
     *
     * A `User`, where this used to resolve the single `customers` row: the
     * login is the thing that spans hauliers, and which of their accounts a
     * given call concerns is `PortalService`'s to work out. A driver or a
     * back-office user gets the 404 they always did — from
     * `ShipperAccounts::home()`, on the first read that needs an account.
     */
    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
