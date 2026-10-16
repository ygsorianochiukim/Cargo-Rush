<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Controllers;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Requests\DeliveryFuelRequest;
use App\Domain\Delivery\Requests\ProofOfDeliveryRequest;
use App\Domain\Delivery\Resources\DeliveryLogResource;
use App\Domain\Delivery\Services\DeliveryService;
use App\Domain\Delivery\Services\TripCostPostingService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends ApiController
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->deliveries->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(DeliveryLogResource::collection($page), $page);
    }

    public function show(DeliveryLog $delivery): JsonResponse
    {
        return $this->item(new DeliveryLogResource($delivery));
    }

    public function proof(ProofOfDeliveryRequest $request, DeliveryLog $delivery): JsonResponse
    {
        $this->assertTheirs($request, $delivery);

        return $this->item(new DeliveryLogResource(
            $this->deliveries->attachProof($delivery, $request->toProof()),
        ));
    }

    public function fuel(DeliveryFuelRequest $request, DeliveryLog $delivery, TripCostPostingService $costs): JsonResponse
    {
        $this->assertTheirs($request, $delivery);

        DB::transaction(function () use ($request, $delivery, $costs): void {
            $delivery->update($request->fuel());
            $costs->sync($delivery);
        });

        return $this->item(new DeliveryLogResource($delivery->refresh()));
    }

    public function report(): JsonResponse
    {
        return $this->payload($this->deliveries->report());
    }

    private function assertTheirs(Request $request, DeliveryLog $delivery): void
    {
        $user = $request->user();
        $trip = $delivery->trip;

        $theirs = match ($user->role) {
            Role::Driver->value => $trip !== null && $trip->isCrewedBy($user->driver?->getKey()),
            Role::Trucker->value => $trip !== null && $trip->trucker_id !== null
                && $trip->trucker_id === $user->trucker?->getKey(),
            Role::TruckerDriver->value => $trip !== null && $trip->trucker_driver_id !== null
                && $trip->trucker_driver_id === $user->truckerDriver?->getKey(),
            default => true,
        };

        abort_unless($theirs, 404, 'That delivery is not yours.');
    }
}
