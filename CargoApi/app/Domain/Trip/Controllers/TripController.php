<?php

declare(strict_types=1);

namespace App\Domain\Trip\Controllers;

use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Requests\ConfirmTripRequest;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Requests\TripRequest;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trip\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip Management — DESIGN.md section 5.1.
 *
 * Thin and resourceful, as section 7.4 requires: it validates through the
 * Request, hands a DTO to the Service, and wraps whatever comes back in the
 * envelope. No query builder and no business rule appears in this file.
 */
class TripController extends ApiController
{
    public function __construct(private readonly TripService $trips) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->trips->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(TripResource::collection($page), $page);
    }

    public function show(Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($trip));
    }

    public function store(TripRequest $request): JsonResponse
    {
        $trip = $this->trips->create($request->toData());

        return $this->item(new TripResource($trip), status: 201);
    }

    public function update(TripRequest $request, Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->trips->update($trip, $request->toData())));
    }

    public function destroy(Trip $trip): JsonResponse
    {
        $this->trips->delete($trip);

        return $this->noContent();
    }

    /**
     * Confirm a request: name the crew, the unit and the time, and it becomes
     * work a driver can start.
     *
     * A verb rather than a status PATCH, because `assigned` is what follows
     * from those four fields being filled in — not a value that sits beside
     * them. This is the one action the tracking desk performs on a customer's
     * request, and the reason a request has to wait for it.
     */
    public function confirm(ConfirmTripRequest $request, Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->trips->confirm($trip, $request->toData())));
    }

    /**
     * Send a unit out. A verb of its own rather than a status PATCH, because
     * dispatching writes a dispatch record too.
     */
    public function dispatchTrip(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'location' => ['required', 'string', 'max:160'],
        ]);

        $this->trips->dispatch($trip, $validated['location']);

        return $this->item(new TripResource($trip->refresh()));
    }

    /**
     * Close it out: delivery log, dispatch record, driver credit, the day's
     * income and the customer's invoice, together.
     *
     * The office's version of the driver's hand-off, and it takes the same
     * proof — a signed name, and a photograph when there is one. It used to
     * accept both as optional, which is how a trip ended up marked delivered
     * with nothing behind it for anybody to chase.
     */
    public function complete(DeliverTripRequest $request, Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->trips->complete($trip, $request->toProof())));
    }
}
