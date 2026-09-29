<?php

declare(strict_types=1);

namespace App\Domain\Trip\Controllers;

use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Requests\ConfirmTripRequest;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Requests\TripRequest;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trip\Services\TripTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    /** What the trip ticket and the dispatch checklist print — see `TripTicketService`. */
    public function ticket(Trip $trip, TripTicketService $tickets): JsonResponse
    {
        return $this->payload($tickets->build($trip));
    }

    public function store(TripRequest $request): JsonResponse
    {
        if (! $this->isPastDelivery($request)) {
            return $this->item(new TripResource($this->trips->create($request->toData())), status: 201);
        }

        // A trip that already happened: booked as ordinary work, then put
        // through the real delivery, dated the day it was delivered.
        $trip = $this->trips->create($this->asAssigned($request));

        return $this->item(new TripResource($this->recordDelivery($request, $trip)), status: 201);
    }

    public function update(TripRequest $request, Trip $trip): JsonResponse
    {
        if (! $this->isPastDelivery($request) || $trip->status === StatusValue::Delivered) {
            return $this->item(new TripResource($this->trips->update($trip, $request->toData())));
        }

        $updated = $this->trips->update($trip, $this->asAssigned($request));

        return $this->item(new TripResource($this->recordDelivery($request, $updated)));
    }

    /** Is the office entering this as already delivered? See `TripRequest`. */
    private function isPastDelivery(TripRequest $request): bool
    {
        return $request->input('status') === StatusValue::Delivered->value;
    }

    /** The trip's details, held as `assigned` until the delivery closes it. */
    private function asAssigned(TripRequest $request): TripData
    {
        return TripData::fromArray([...$request->payload(), 'status' => StatusValue::Assigned->value]);
    }

    /**
     * The real delivery, back-dated: the log, the day's sheet income, the
     * wallet and the invoice all carry the delivered date — the scheduled time
     * when none is given — so an old trip is on the books as if closed then.
     */
    private function recordDelivery(TripRequest $request, Trip $trip): Trip
    {
        $at = $request->filled('delivered_at')
            ? Carbon::parse($request->input('delivered_at'))
            : $trip->scheduled_at;

        return $this->trips->complete(
            $trip,
            new ProofData(receiver_name: $request->string('receiver_name')->value() ?: 'Recorded by the office'),
            $at,
        );
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
