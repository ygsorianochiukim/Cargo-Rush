<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Controllers;

use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Requests\MaintenanceJobRequest;
use App\Domain\Vehicle\Resources\MaintenanceJobResource;
use App\Domain\Vehicle\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends ApiController
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            ...$this->filters($request),
            ...array_filter($request->only(['supplier_id', 'costed']), static fn ($v): bool => $v !== null && $v !== ''),
        ];

        $page = $this->maintenance->paginate($filters, $this->perPage($request, 50));

        return $this->collection(
            MaintenanceJobResource::collection($page),
            $page,
            ['costed_total_cents' => $this->maintenance->costedTotal($filters)],
        );
    }

    public function show(string $job): JsonResponse
    {
        return $this->item(new MaintenanceJobResource(
            $this->maintenance->find($job)->load(['vehicle:id,plate,model,odometer_km', 'supplier:id,name'])
        ));
    }

    public function store(MaintenanceJobRequest $request): JsonResponse
    {
        $vehicle = Vehicle::query()->findOrFail($request->validated('vehicle_id'));

        $job = $this->maintenance->save($vehicle, $request->toAttributes());

        return $this->item($this->resource($job), status: 201);
    }

    public function update(MaintenanceJobRequest $request, string $job): JsonResponse
    {
        $existing = $this->maintenance->find($job);

        $vehicle = $request->filled('vehicle_id')
            ? Vehicle::query()->findOrFail($request->validated('vehicle_id'))
            : $existing->vehicle;

        abort_if($vehicle === null, 422, 'That job has no unit on it. Pick the truck it was done on.');

        $job = $this->maintenance->save($vehicle, $request->toAttributes(), $existing);

        return $this->item($this->resource($job));
    }

    public function destroy(string $job): JsonResponse
    {
        $this->maintenance->remove($this->maintenance->find($job));

        return $this->noContent();
    }

    private function resource(MaintenanceJob $job): MaintenanceJobResource
    {
        return new MaintenanceJobResource($job->load(['vehicle:id,plate,model,odometer_km', 'supplier:id,name']));
    }
}
