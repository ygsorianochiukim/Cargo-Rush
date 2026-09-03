<?php

declare(strict_types=1);

namespace App\Domain\Driver\Controllers;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Requests\DriverRequest;
use App\Domain\Driver\Resources\DriverResource;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Drivers Management — LTMS violations, licences, driver status. */
class DriverController extends ApiController
{
    public function __construct(private readonly DriverService $drivers) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->drivers->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(DriverResource::collection($page), $page);
    }

    public function show(Driver $driver): JsonResponse
    {
        return $this->item(new DriverResource($driver));
    }

    public function store(DriverRequest $request): JsonResponse
    {
        return $this->item(new DriverResource($this->drivers->create($request->toData())), status: 201);
    }

    public function update(DriverRequest $request, Driver $driver): JsonResponse
    {
        return $this->item(new DriverResource($this->drivers->update($driver, $request->toData())));
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $this->drivers->delete($driver);

        return $this->noContent();
    }

    /** The availability switch on the driver app's dashboard. */
    public function availability(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate(['available' => ['required', 'boolean']]);

        return $this->item(new DriverResource(
            $this->drivers->setAvailability($driver, $validated['available'])
        ));
    }
}
