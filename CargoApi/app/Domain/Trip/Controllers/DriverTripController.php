<?php

declare(strict_types=1);

namespace App\Domain\Trip\Controllers;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Requests\StartTripRequest;
use App\Domain\Trip\Resources\CargoResource;
use App\Domain\Trip\Resources\CurrentTripResource;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trip\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver's own work — what `cargoApp` asks for on its Dashboard and Cargo
 * screens.
 *
 * Separate from `TripController` on purpose. These endpoints are scoped to the
 * caller and take no id, so a driver cannot read another driver's run by
 * changing a number in the URL. The back office reads the same rows through
 * the module controller, with the permissions that come with it.
 */
class DriverTripController extends ApiController
{
    public function __construct(
        private readonly TripService $trips,
        private readonly DriverService $drivers,
    ) {}

    /** The trip they are on right now, or 204 when they are between runs. */
    public function current(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $trip = $this->trips->currentForDriver($driver->id);

        return $trip === null ? $this->noContent() : $this->item(new CurrentTripResource($trip));
    }

    /** Assigned work that has not started. */
    public function pending(Request $request): JsonResponse
    {
        $trips = $this->trips->pendingForDriver($this->driver($request)->id);

        return $this->collection(TripResource::collection($trips), $trips);
    }

    /**
     * Cargo Details for the current run.
     *
     * Its own endpoint rather than a flag on `current`, because the shape is
     * different: this is the trip joined to its dispatch and delivery records.
     */
    public function cargo(Request $request): JsonResponse
    {
        $trip = $this->trips->currentForDriver($this->driver($request)->id);

        // No current run means no cargo. A 204 says that without pretending
        // an empty object is a shipment.
        return $trip === null
            ? $this->noContent()
            : $this->item(new CargoResource($trip->load(['dispatchRecord', 'deliveryLog'])));
    }

    /** Work booked for a later day. */
    public function upcoming(Request $request): JsonResponse
    {
        $trips = $this->trips->upcomingForDriver($this->driver($request)->id);

        return $this->collection(TripResource::collection($trips), $trips);
    }

    /**
     * Leave on a run: pending → in transit, opening the dispatch record.
     *
     * The one driver call that names a trip, because a driver with three runs
     * waiting has to say which one they are starting. The service checks it
     * belongs to the caller before acting on it.
     */
    public function start(StartTripRequest $request, Trip $trip): JsonResponse
    {
        $started = $this->trips->startForDriver(
            $this->driver($request)->id,
            $trip,
            $request->validated()['location'] ?? null,
        );

        return $this->item(new TripResource($started));
    }

    /**
     * Hand the run over: in transit → delivered, with the proof.
     *
     * Takes no trip id for the same reason the reads above do not — it acts on
     * whatever run the caller is actually on.
     *
     * Multipart rather than JSON, because the proof is a photograph. The
     * reference the driver used to have to type is assigned by the system.
     */
    public function deliver(DeliverTripRequest $request): JsonResponse
    {
        $trip = $this->trips->deliverForDriver(
            $this->driver($request)->id,
            $request->toProof(),
        );

        return $this->item(new TripResource($trip));
    }

    /**
     * The driver record behind the login. A back-office user calling these has
     * no driver row, and a 404 says that plainly rather than returning an
     * empty list that looks like an idle day.
     */
    private function driver(Request $request): Driver
    {
        $driver = $this->drivers->forUser($request->user()->id);

        abort_if($driver === null, 404, 'This account is not linked to a driver record.');

        return $driver;
    }
}
