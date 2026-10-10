<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Controllers;

use App\Domain\Delivery\Requests\ProofOfDeliveryRequest;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Resources\CurrentTripResource;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trip\Services\DispatchChecklistService;
use App\Domain\Trip\Services\TripTicketService;
use App\Domain\Trucker\Models\TruckerDriver;
use App\Domain\Trucker\Resources\TruckerDriverResource;
use App\Domain\Trucker\Services\JobBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrewController extends ApiController
{
    public function __construct(
        private readonly JobBoardService $board,
        private readonly InspectionService $inspections,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        return $this->item(new TruckerDriverResource($this->me($request)->load('user:id,email')));
    }

    public function trips(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $trips = $this->board->mine($me->trucker, $me);

        return $this->collection(TripResource::collection($trips), $trips);
    }

    public function current(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $trip = $this->board->current($me->trucker, $me)?->load(['latestPing', 'helpers', 'latestInspection']);

        return $trip === null ? $this->noContent() : $this->item(new CurrentTripResource($trip));
    }

    public function checklist(): JsonResponse
    {
        return $this->payload($this->inspections->checklist());
    }

    public function inspect(Request $request, string $tripId): JsonResponse
    {
        $validated = $request->validate([
            'results' => ['required', 'array'],
            'results.*' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $me = $this->mayDrive($request);
        $trip = $this->board->ownedBy($me->trucker, $tripId, $me);

        abort_unless(
            in_array($trip->status, [StatusValue::Assigned, StatusValue::Overdue], true),
            422,
            'This run has already left — the check is done before it starts.',
        );

        $inspection = $this->inspections->submitForCrew(
            $trip,
            $me,
            array_map(static fn ($ok): bool => (bool) $ok, $validated['results']),
            $validated['notes'] ?? null,
        );

        return $this->item(
            new TripResource($trip->refresh()->load(['customer:id,name', 'truckerVehicle:id,plate', 'truckerDriver:id,name'])),
            ['good_to_go' => $inspection->good_to_go, 'failures' => $inspection->failures()],
            201,
        );
    }

    public function dispatchChecklist(): JsonResponse
    {
        return $this->payload(TripTicketService::checklist());
    }

    public function answerDispatchChecklist(Request $request, string $tripId, DispatchChecklistService $checklists): JsonResponse
    {
        $validated = $request->validate(DispatchChecklistService::rules());
        $me = $this->mayDrive($request);
        $trip = $this->board->ownedBy($me->trucker, $tripId, $me);

        return $this->item(new TripResource($checklists->record(
            $trip,
            $validated['answers'],
            $validated['remarks'] ?? null,
            $me->name,
        )));
    }

    public function history(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $trips = $this->board->history($me->trucker, 50, $me);

        return $this->collection(TripResource::collection($trips), $trips);
    }

    public function start(Request $request, string $tripId): JsonResponse
    {
        $me = $this->mayDrive($request);

        $trip = $this->board->start($me->trucker, $tripId, $request->string('location')->value() ?: null, $me);

        return $this->item(new TripResource($trip));
    }

    public function deliver(DeliverTripRequest $request, string $tripId): JsonResponse
    {
        $me = $this->mayDrive($request);

        $trip = $this->board->deliver($me->trucker, $tripId, $request->toProof(), $me);

        return $this->item(new TripResource($trip));
    }

    public function proof(ProofOfDeliveryRequest $request, string $tripId): JsonResponse
    {
        $me = $this->me($request);

        $trip = $this->board->attachProof($me->trucker, $tripId, $request->toProof(), $me);

        return $this->item(new TripResource($trip));
    }

    private function me(Request $request): TruckerDriver
    {
        $driver = TruckerDriver::query()
            ->with('trucker')
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->first();

        abort_if($driver === null || $driver->trucker === null, 404, 'This account is not linked to a trucker\'s driver.');

        return $driver;
    }

    private function mayDrive(Request $request): TruckerDriver
    {
        $driver = $this->me($request);

        abort_unless(
            $driver->mayDrive(),
            403,
            'You cannot drive runs right now. Ask the trucker you drive for.',
        );

        return $driver;
    }
}
