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

/**
 * Truck Maintenance — what the fleet spends keeping its units on the road.
 *
 * The same rows `VehicleController::maintenance()` serves, read the other way
 * round. That one answers the yard's question — what is booked on this truck —
 * from inside a unit's own screen. This answers the office's: what has
 * servicing come to, on which units, from which garages, and what is still
 * booked but not costed.
 *
 * ## Why it is a screen of its own rather than a filter on Other Expenses
 *
 * Because a service is not an expense row, and making it one is what the last
 * shape of this got wrong. An oil change filed on Other Expenses put the
 * fleet's service history inside a list of meals and tarpaulins, where neither
 * could be found — and the cost never reached the truck's own sheet, so
 * Profitability showed a unit that had apparently never been serviced.
 *
 * A job here carries the unit, the garage, the day the work was done and what
 * it came to, and the money lands in that truck's Maintenance column. Other
 * Expenses keeps what it is for: the spend that belongs to the period rather
 * than to any one unit.
 *
 * ## Writing is scoped to the unit, even here
 *
 * Every write resolves the vehicle first and goes through
 * `MaintenanceService`, exactly as the nested routes do. Nothing about the
 * money changes because the row was opened from a different screen — the
 * posting to the daily sheet, and the guarantee that it happens once however
 * many times a figure is corrected, is the service's and stays there.
 */
class MaintenanceController extends ApiController
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    /**
     * The fleet's servicing, newest work first.
     *
     * The total is sent as meta rather than summed in the client: a client can
     * only add up the rows it was handed, and "what did servicing cost this
     * quarter" is a question about the window, not about the first page of it.
     */
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

    /** Book a service against a unit, or file one that has already been done. */
    public function store(MaintenanceJobRequest $request): JsonResponse
    {
        $vehicle = Vehicle::query()->findOrFail($request->validated('vehicle_id'));

        $job = $this->maintenance->save($vehicle, $request->toAttributes());

        return $this->item($this->resource($job), status: 201);
    }

    /**
     * Correct one — most often to put the garage's figure on it.
     *
     * A job may be moved to another unit here, which the nested route cannot
     * do: it resolves the row off the vehicle in the path, so the vehicle is
     * the one thing it can never change. Moving it is a real correction — a
     * receipt keyed against the wrong plate — and the sheet follows, because
     * the save takes the cost off whatever unit and day it was on before
     * putting it where the job now says it belongs.
     */
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

    /**
     * Take a job off the books.
     *
     * The unit's daily sheet is credited back first — a deleted job that left
     * its cost on the sheet would overstate what that truck cost for the rest
     * of time, with nothing on the row to say where the figure came from. See
     * `MaintenanceService::remove`.
     */
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
