<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Controllers;

use App\Domain\Inspection\Requests\InspectionRequest;
use App\Domain\Inspection\Resources\InspectionResource;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Resources\MaintenanceJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * On-boarding trips inspection, and unit maintenance — the two mobile-only
 * capture modules (DESIGN.md section 5.4).
 */
class InspectionController extends ApiController
{
    public function __construct(private readonly InspectionService $inspections) {}

    /** The checklist the driver app renders. */
    public function checklist(): JsonResponse
    {
        return $this->payload($this->inspections->checklist());
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->inspections->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(InspectionResource::collection($page), $page);
    }

    /** The API decides `good_to_go`, so the response is worth reading back. */
    public function store(InspectionRequest $request): JsonResponse
    {
        return $this->item(new InspectionResource($this->inspections->submit($request->toData())), status: 201);
    }

    public function maintenance(Vehicle $vehicle): JsonResponse
    {
        $jobs = $this->inspections->maintenanceForVehicle($vehicle->id);

        return $this->collection(MaintenanceJobResource::collection($jobs), $jobs);
    }
}
