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
use App\Domain\Trip\Services\DispatchChecklistService;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trip\Services\TripTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverTripController extends ApiController
{
    public function __construct(
        private readonly TripService $trips,
        private readonly DriverService $drivers,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $trip = $this->trips->currentForDriver($driver->id);

        return $trip === null ? $this->noContent() : $this->item(new CurrentTripResource($trip));
    }

    public function pending(Request $request): JsonResponse
    {
        $trips = $this->trips->pendingForDriver($this->driver($request)->id);

        return $this->collection(TripResource::collection($trips), $trips);
    }

    public function cargo(Request $request): JsonResponse
    {
        $trip = $this->trips->currentForDriver($this->driver($request)->id);

        return $trip === null
            ? $this->noContent()
            : $this->item(new CargoResource($trip->load(['dispatchRecord', 'deliveryLog'])));
    }

    public function upcoming(Request $request): JsonResponse
    {
        $trips = $this->trips->upcomingForDriver($this->driver($request)->id);

        return $this->collection(TripResource::collection($trips), $trips);
    }

    public function start(StartTripRequest $request, Trip $trip): JsonResponse
    {
        $started = $this->trips->startForDriver(
            $this->driver($request)->id,
            $trip,
            $request->validated()['location'] ?? null,
        );

        return $this->item(new TripResource($started));
    }

    public function deliver(DeliverTripRequest $request): JsonResponse
    {
        $trip = $this->trips->deliverForDriver(
            $this->driver($request)->id,
            $request->toProof(),
        );

        return $this->item(new TripResource($trip));
    }

    public function dispatchChecklist(): JsonResponse
    {
        return $this->payload(TripTicketService::checklist());
    }

    public function answerDispatchChecklist(Request $request, Trip $trip, DispatchChecklistService $checklists): JsonResponse
    {
        $validated = $request->validate(DispatchChecklistService::rules());
        $driver = $this->driver($request);

        abort_unless($trip->driver_id === $driver->id, 403, 'This run is not yours to check.');

        return $this->item(new TripResource($checklists->record(
            $trip,
            $validated['answers'],
            $validated['remarks'] ?? null,
            $driver->name,
        )));
    }

    private function driver(Request $request): Driver
    {
        $driver = $this->drivers->forUser($request->user()->id);

        abort_if($driver === null, 404, 'This account is not linked to a driver record.');

        return $driver;
    }
}
