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

/**
 * A trucker's driver, on their own login — `crew/*`.
 *
 * The same runs the owner's `partner/trips` endpoints serve, narrowed to the
 * ones the owner handed this driver. Scoped to the caller's `trucker_drivers`
 * row and nothing else: no path takes an id that identifies the caller, and a
 * run id they were not given reads as a 404.
 *
 * What is missing is deliberate. No board and no accept — taking work is the
 * owner's decision. No wallet — the money is the owner's. No trucks, no other
 * drivers.
 */
class CrewController extends ApiController
{
    public function __construct(
        private readonly JobBoardService $board,
        private readonly InspectionService $inspections,
    ) {}

    /** Who they are and who they drive for. */
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

    /**
     * The run they are on, in the shape the Cargo Rush driver's Tracking
     * screen reads — progress, the last reported position and both ends of
     * the route — so the map and the GPS reporting are the same screen.
     */
    public function current(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $trip = $this->board->current($me->trucker, $me)?->load(['latestPing', 'helpers', 'latestInspection']);

        return $trip === null ? $this->noContent() : $this->item(new CurrentTripResource($trip));
    }

    /** The pre-trip checklist — the same seven items a Cargo Rush driver answers. */
    public function checklist(): JsonResponse
    {
        return $this->payload($this->inspections->checklist());
    }

    /**
     * Their pre-trip check for one of their runs.
     *
     * Before it leaves only: a run on the road was cleared on the way out.
     * The verdict is the API's (`InspectionService::isGoodToGo`), never the
     * handset's, and a fail goes to their trucker, not to Cargo Rush.
     */
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

    /** The dispatch checklist's lines — the same form a Cargo Rush driver answers. */
    public function dispatchChecklist(): JsonResponse
    {
        return $this->payload(TripTicketService::checklist());
    }

    /** Their answers to the dispatch checklist for one of their runs. */
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

    /**
     * The caller's own driver record. A 404 for any other login, the way
     * `PartnerController::me()` answers somebody who is not a trucker.
     */
    private function me(Request $request): TruckerDriver
    {
        $driver = TruckerDriver::query()
            ->with('trucker')
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->first();

        abort_if($driver === null || $driver->trucker === null, 404, 'This account is not linked to a trucker\'s driver.');

        return $driver;
    }

    /**
     * Starting and handing over are driving, and need both standings: their
     * owner has not stood them down, and the office has not stood the owner
     * down. Reading their runs and sending a late photo do not.
     */
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
