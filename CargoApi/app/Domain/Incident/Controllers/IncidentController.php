<?php

declare(strict_types=1);

namespace App\Domain\Incident\Controllers;

use App\Domain\Incident\Models\Incident;
use App\Domain\Incident\Requests\IncidentRequest;
use App\Domain\Incident\Resources\IncidentResource;
use App\Domain\Incident\Services\IncidentService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Incident Management — records with a time and a place. */
class IncidentController extends ApiController
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->incidents->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(IncidentResource::collection($page), $page);
    }

    public function show(Incident $incident): JsonResponse
    {
        return $this->item(new IncidentResource($incident));
    }

    /** Reporting one also raises the notification the Support group reads. */
    public function store(IncidentRequest $request): JsonResponse
    {
        return $this->item(new IncidentResource($this->incidents->report($request->toData())), status: 201);
    }

    public function update(IncidentRequest $request, Incident $incident): JsonResponse
    {
        return $this->item(new IncidentResource($this->incidents->update($incident, $request->toData())));
    }

    public function destroy(Incident $incident): JsonResponse
    {
        $this->incidents->delete($incident);

        return $this->noContent();
    }
}
