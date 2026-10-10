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

class GpsController extends ApiController
{
    public function __construct(private readonly GpsService $gps) {}

    public function index(): JsonResponse
    {
        $units = $this->gps->liveUnits();

        return $this->collection(GpsUnitResource::collection($units), $units);
    }

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

    public function tracking(Trip $trip): JsonResponse
    {
        $state = $this->gps->trackingState($trip);

        abort_if($state === null, 404, 'This trip has not reported a position yet.');

        return $this->payload($state);
    }
}
