<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Controllers;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Requests\MaintenanceJobRequest;
use App\Domain\Vehicle\Requests\VehicleRequest;
use App\Domain\Vehicle\Resources\MaintenanceJobResource;
use App\Domain\Vehicle\Resources\VehicleResource;
use App\Domain\Vehicle\Services\MaintenanceService;
use App\Domain\Vehicle\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vehicle Management — registration, capacity, status, maintenance. */
class VehicleController extends ApiController
{
    public function __construct(
        private readonly VehicleService $vehicles,
        private readonly MaintenanceService $maintenance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->vehicles->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(VehicleResource::collection($page), $page);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        return $this->item(new VehicleResource($vehicle));
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        return $this->item(new VehicleResource($this->vehicles->create($request->toData())), status: 201);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        return $this->item(new VehicleResource($this->vehicles->update($vehicle, $request->toData())));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->vehicles->delete($vehicle);

        return $this->noContent();
    }

    /** Taking a unit off the road also releases its driver, so it is a verb. */
    public function status(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(StatusValue::values())],
        ]);

        return $this->item(new VehicleResource(
            $this->vehicles->setStatus($vehicle, StatusValue::from($validated['status']))
        ));
    }

    /** The maintenance jobs booked against one unit, and what each cost. */
    public function maintenance(Vehicle $vehicle): JsonResponse
    {
        $jobs = $vehicle->maintenanceJobs()
            ->with(['vehicle:id,plate,odometer_km', 'supplier:id,name'])
            ->orderByDesc('completed_on')
            ->orderBy('due_at')
            ->get();

        return $this->collection(MaintenanceJobResource::collection($jobs), $jobs);
    }

    /**
     * Book a service against this unit.
     *
     * Through `MaintenanceService` rather than straight onto the relation,
     * because a job that carries a cost is money: the figure has to reach the
     * unit's daily sheet, exactly once, however many times the row is saved.
     */
    public function storeMaintenance(MaintenanceJobRequest $request, Vehicle $vehicle): JsonResponse
    {
        $job = $this->maintenance->save($vehicle, $request->toAttributes());

        return $this->item(
            new MaintenanceJobResource($job->load(['vehicle:id,plate,odometer_km', 'supplier:id,name'])),
            status: 201,
        );
    }

    /**
     * Correct one — most often to put the garage's figure on it.
     *
     * Scoped to the unit in the path rather than resolved on its own, so a job
     * id from another vehicle cannot be edited through this route.
     */
    public function updateMaintenance(
        MaintenanceJobRequest $request,
        Vehicle $vehicle,
        string $jobId,
    ): JsonResponse {
        $job = $vehicle->maintenanceJobs()->findOrFail($jobId);

        $job = $this->maintenance->save($vehicle, $request->toAttributes(), $job);

        return $this->item(
            new MaintenanceJobResource($job->load(['vehicle:id,plate,odometer_km', 'supplier:id,name'])),
        );
    }

    /**
     * Take a job off the unit.
     *
     * The daily sheet is credited back first — see `MaintenanceService::remove`.
     */
    public function destroyMaintenance(Vehicle $vehicle, string $jobId): JsonResponse
    {
        $this->maintenance->remove($vehicle->maintenanceJobs()->findOrFail($jobId));

        return $this->noContent();
    }
}
