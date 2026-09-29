<?php

declare(strict_types=1);

namespace App\Domain\Gps\Controllers;

use App\Domain\Gps\Requests\GpsPingRequest;
use App\Domain\Gps\Resources\GpsUnitResource;
use App\Domain\Gps\Services\GpsService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GPS Dashboard (web, read) and GPS Tracking (mobile, write).
 *
 * The split is deliberate: the handset is the position source and the back
 * office is a reader — DESIGN.md section 5.4.
 */
class GpsController extends ApiController
{
    public function __construct(private readonly GpsService $gps) {}

    /** Every unit currently on the road, with its latest position. */
    public function index(): JsonResponse
    {
        $units = $this->gps->liveUnits();

        return $this->collection(GpsUnitResource::collection($units), $units);
    }

    /** What the handset posts while it is moving. */
    public function store(GpsPingRequest $request): JsonResponse
    {
        $this->assertTheirs($request, (string) $request->input('trip_id'));

        $ping = $this->gps->record($request->toData());

        return $this->payload([
            'id' => $ping->id,
            'trip_id' => $ping->trip_id,
            'recorded_at' => $ping->recorded_at->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Whoever is on the road may only report the run they are on.
     *
     * `gps.write` is held by every kind of driver — Cargo Rush's, a trucker,
     * and a trucker's own driver — and without this any of them could put a
     * position on anybody's run, which is the office map lying. A 404 rather
     * than a 403, the way the delivery endpoints answer. The office itself is
     * not restricted.
     */
    private function assertTheirs(Request $request, string $tripId): void
    {
        $user = $request->user();
        $trip = Trip::query()->find($tripId);

        $theirs = match ($user->role) {
            Role::Driver->value => $trip !== null && $trip->isCrewedBy($user->driver?->getKey()),
            Role::Trucker->value => $trip !== null && $trip->trucker_id !== null
                && $trip->trucker_id === $user->trucker?->getKey(),
            Role::TruckerDriver->value => $trip !== null && $trip->trucker_driver_id !== null
                && $trip->trucker_driver_id === $user->truckerDriver?->getKey(),
            default => true,
        };

        abort_unless($theirs, 404, 'That run is not yours to report on.');
    }

    /** The mobile Tracking screen: A to B, progress, and average speed. */
    public function tracking(Trip $trip): JsonResponse
    {
        $state = $this->gps->trackingState($trip);

        // A trip that has never pinged has no track yet, and saying so with a
        // 404 is clearer than an object of zeroes that looks like a stopped truck.
        abort_if($state === null, 404, 'This trip has not reported a position yet.');

        return $this->payload($state);
    }
}
