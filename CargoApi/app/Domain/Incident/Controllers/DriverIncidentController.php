<?php

declare(strict_types=1);

namespace App\Domain\Incident\Controllers;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Incident\Requests\ReportIncidentRequest;
use App\Domain\Incident\Resources\IncidentResource;
use App\Domain\Incident\Services\IncidentService;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverIncidentController extends ApiController
{
    public function __construct(
        private readonly IncidentService $incidents,
        private readonly DriverService $drivers,
        private readonly TripService $trips,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $incidents = $this->incidents->forDriver($this->driver($request)->id);

        return $this->collection(IncidentResource::collection($incidents), $incidents);
    }

    public function store(ReportIncidentRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        $trip = $this->trips->currentForDriver($driver->id);

        $incident = $this->incidents->report($request->toData(
            driverId: $driver->id,
            vehicleId: $trip?->vehicle_id ?? $driver->vehicle?->id,
            tripId: $trip?->id,
        ));

        return $this->item(new IncidentResource($incident), status: 201);
    }

    private function driver(Request $request): Driver
    {
        $driver = $this->drivers->forUser($request->user()->id);

        abort_if($driver === null, 404, 'This account is not linked to a driver record.');

        return $driver;
    }
}
