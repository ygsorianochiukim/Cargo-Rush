<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Controllers;

use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Requests\FuelRecordRequest;
use App\Domain\Fuel\Resources\FuelRecordResource;
use App\Domain\Fuel\Services\FuelService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Fuel Expense Monitoring — daily budget, requests, odometer, receipts,
 * consumption history and projection.
 */
class FuelController extends ApiController
{
    public function __construct(private readonly FuelService $fuel) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->fuel->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(FuelRecordResource::collection($page), $page);
    }

    public function show(FuelRecord $fuel): JsonResponse
    {
        return $this->item(new FuelRecordResource($fuel));
    }

    public function store(FuelRecordRequest $request): JsonResponse
    {
        return $this->item(new FuelRecordResource($this->fuel->log($request->toData())), status: 201);
    }

    public function update(FuelRecordRequest $request, FuelRecord $fuel): JsonResponse
    {
        return $this->item(new FuelRecordResource($this->fuel->update($fuel, $request->toData())));
    }

    public function destroy(FuelRecord $fuel): JsonResponse
    {
        $this->fuel->delete($fuel);

        return $this->noContent();
    }

    /** Budget, spend so far, and the month projection. */
    public function budget(Request $request): JsonResponse
    {
        $on = $request->filled('date') ? Carbon::parse($request->string('date')->toString()) : null;

        return $this->payload($this->fuel->budget($on));
    }
}
