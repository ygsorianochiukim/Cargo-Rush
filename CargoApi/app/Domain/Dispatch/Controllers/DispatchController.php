<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Controllers;

use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Dispatch\Resources\DispatchRecordResource;
use App\Domain\Dispatch\Services\DispatchService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dispatch Monitoring — dispatch records, time and location. */
class DispatchController extends ApiController
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->dispatch->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(DispatchRecordResource::collection($page), $page);
    }

    public function show(DispatchRecord $dispatch): JsonResponse
    {
        return $this->item(new DispatchRecordResource($dispatch));
    }

    /** Records are born when a trip is dispatched, so arrival is the one verb. */
    public function arrive(DispatchRecord $dispatch): JsonResponse
    {
        return $this->item(new DispatchRecordResource($this->dispatch->markArrived($dispatch)));
    }
}
