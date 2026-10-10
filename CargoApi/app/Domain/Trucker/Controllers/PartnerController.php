<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Controllers;

use App\Domain\Delivery\Requests\ProofOfDeliveryRequest;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Requests\TruckerDriverRequest;
use App\Domain\Trucker\Requests\TruckerVehiclePhotosRequest;
use App\Domain\Trucker\Requests\TruckerVehicleRequest;
use App\Domain\Trucker\Resources\JobResource;
use App\Domain\Trucker\Resources\TruckerDriverResource;
use App\Domain\Trucker\Resources\TruckerResource;
use App\Domain\Trucker\Resources\TruckerVehicleResource;
use App\Domain\Trucker\Resources\WalletEntryResource;
use App\Domain\Trucker\Services\CrewService;
use App\Domain\Trucker\Services\JobBoardService;
use App\Domain\Trucker\Services\TruckerService;
use App\Domain\Trucker\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends ApiController
{
    public function __construct(
        private readonly TruckerService $truckers,
        private readonly JobBoardService $board,
        private readonly WalletService $wallet,
        private readonly CrewService $crew,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $trucker = $this->me($request);

        return $this->item(
            new TruckerResource($trucker->loadMissing('vehicles.category')),
            ['balance_cents' => $this->wallet->balance($trucker)],
        );
    }

    public function jobs(Request $request): JsonResponse
    {
        $trucker = $this->me($request);

        $jobs = $this->board->open(
            $trucker,
            $request->has('lat') ? (float) $request->input('lat') : null,
            $request->has('lng') ? (float) $request->input('lng') : null,
        );

        $rate = $trucker->commissionRateBp();

        return $this->collection(
            JobResource::collection($jobs->map(fn ($trip) => new JobResource(
                $trip,
                $rate,
                $this->wallet->partnerTakeOn($trip, $rate),
            ))),
            $jobs,
            [
                'commission_bp' => $rate,
                'can_take_work' => $trucker->canTakeWork(),
                'status' => $trucker->status->value,
                'has_truck' => $trucker->activeVehicle() !== null,
            ],
        );
    }

    public function accept(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->board->accept($this->me($request), $tripId);

        return $this->item(new TripResource($trip), [], 201);
    }

    public function trips(Request $request): JsonResponse
    {
        $trips = $this->board->mine($this->me($request));

        return $this->collection(TripResource::collection($trips), $trips);
    }

    public function current(Request $request): JsonResponse
    {
        $trip = $this->board->current($this->me($request));

        return $trip === null
            ? $this->noContent()
            : $this->item(new TripResource($trip));
    }

    public function history(Request $request): JsonResponse
    {
        $trips = $this->board->history($this->me($request));

        return $this->collection(TripResource::collection($trips), $trips);
    }

    public function start(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->board->start(
            $this->me($request),
            $tripId,
            $request->string('location')->value() ?: null,
        );

        return $this->item(new TripResource($trip));
    }

    public function deliver(DeliverTripRequest $request, string $tripId): JsonResponse
    {
        $trip = $this->board->deliver($this->me($request), $tripId, $request->toProof());

        return $this->item(new TripResource($trip));
    }

    public function proof(ProofOfDeliveryRequest $request, string $tripId): JsonResponse
    {
        $trip = $this->board->attachProof($this->me($request), $tripId, $request->toProof());

        return $this->item(new TripResource($trip));
    }

    public function availability(Request $request): JsonResponse
    {
        $trucker = $this->truckers->setOnline(
            $this->me($request),
            $request->boolean('is_online'),
            $request->has('lat') ? (float) $request->input('lat') : null,
            $request->has('lng') ? (float) $request->input('lng') : null,
        );

        return $this->item(new TruckerResource($trucker));
    }

    public function position(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $trucker = $this->truckers->reportPosition(
            $this->me($request),
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        return $this->item(new TruckerResource($trucker));
    }

    public function wallet(Request $request): JsonResponse
    {
        $trucker = $this->me($request);

        $entries = $this->wallet->statement(
            $trucker,
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        );

        return $this->payload(
            [
                ...$this->wallet->summary($trucker),
                'entries' => WalletEntryResource::collection($entries)->resolve(),
            ],
            ['commission_bp' => $trucker->commissionRateBp()],
        );
    }

    public function vehicles(Request $request): JsonResponse
    {
        $vehicles = $this->truckers->vehicles($this->me($request));

        return $this->collection(TruckerVehicleResource::collection($vehicles), $vehicles);
    }

    public function saveVehicle(TruckerVehicleRequest $request, ?string $vehicleId = null): JsonResponse
    {
        $trucker = $this->me($request);

        abort_unless(
            $trucker->isVetted(),
            403,
            'Your account is waiting for approval. You can add trucks once the office approves you.',
        );

        $vehicle = $this->truckers->saveVehicle(
            $trucker,
            $request->details(),
            $vehicleId,
            $request->photos(),
        );

        return $this->item(new TruckerVehicleResource($vehicle), [], $vehicleId === null ? 201 : 200);
    }

    public function vehiclePhotos(TruckerVehiclePhotosRequest $request, string $vehicleId): JsonResponse
    {
        $trucker = $this->me($request);

        abort_unless(
            $trucker->isVetted(),
            403,
            'Your account is waiting for approval. You can add trucks once the office approves you.',
        );

        return $this->item(new TruckerVehicleResource(
            $this->truckers->resubmitPhotos($trucker, $vehicleId, $request->photos()),
        ));
    }

    public function drivers(Request $request): JsonResponse
    {
        $drivers = $this->crew->drivers($this->me($request));

        return $this->collection(TruckerDriverResource::collection($drivers), $drivers);
    }

    public function saveDriver(TruckerDriverRequest $request, ?string $driverId = null): JsonResponse
    {
        $trucker = $this->me($request);

        $driver = $driverId === null
            ? $this->crew->add($trucker, $request->validated())
            : $this->crew->update($trucker, $driverId, $request->validated());

        return $this->item(new TruckerDriverResource($driver), [], $driverId === null ? 201 : 200);
    }

    public function assignDriver(Request $request, string $tripId): JsonResponse
    {
        $validated = $request->validate([
            'trucker_driver_id' => ['present', 'nullable', 'string', 'max:26'],
            'trucker_vehicle_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        $trip = $this->crew->assign(
            $this->me($request),
            $tripId,
            $validated['trucker_driver_id'],
            $validated['trucker_vehicle_id'] ?? null,
        );

        return $this->item(new TripResource($trip));
    }

    public function removeDriver(Request $request, string $driverId): JsonResponse
    {
        $this->crew->remove($this->me($request), $driverId);

        return $this->noContent();
    }

    private function me(Request $request): Trucker
    {
        $trucker = $this->truckers->forUser((int) $request->user()->getAuthIdentifier());

        abort_if($trucker === null, 404, 'This account is not linked to a trucker record.');

        return $trucker;
    }
}
