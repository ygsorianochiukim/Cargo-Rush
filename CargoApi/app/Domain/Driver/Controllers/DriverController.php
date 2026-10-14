<?php

declare(strict_types=1);

namespace App\Domain\Driver\Controllers;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Requests\DriverRequest;
use App\Domain\Driver\Resources\DriverResource;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends ApiController
{
    public function __construct(private readonly DriverService $drivers) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['role' => ['sometimes', Rule::in([...CrewRole::values(), 'all'])]]);

        $page = $this->drivers->paginate(
            [...$this->filters($request), ...$request->only('role')],
            $this->perPage($request),
        );

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

    public function availability(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate(['available' => ['required', 'boolean']]);

        return $this->item(new DriverResource(
            $this->drivers->setAvailability($driver, $validated['available'])
        ));
    }

    public function ownAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate(['available' => ['required', 'boolean']]);

        $driver = $this->drivers->forUser($request->user()->id);

        abort_if($driver === null, 404, 'This account is not linked to a driver record.');

        return $this->item(new DriverResource(
            $this->drivers->setAvailability($driver, $validated['available'])
        ));
    }
}
