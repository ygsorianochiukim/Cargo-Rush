<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Controllers;

use App\Domain\Delivery\Requests\ProofOfDeliveryRequest;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Requests\TruckerVehicleRequest;
use App\Domain\Trucker\Resources\JobResource;
use App\Domain\Trucker\Resources\TruckerResource;
use App\Domain\Trucker\Resources\TruckerVehicleResource;
use App\Domain\Trucker\Resources\WalletEntryResource;
use App\Domain\Trucker\Services\JobBoardService;
use App\Domain\Trucker\Services\TruckerService;
use App\Domain\Trucker\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The partner's own screens — everything `cargoApp` calls when a trucker is
 * holding it.
 *
 * Scoped to the caller's `truckers` row and to nothing else, exactly as
 * `DriverTripController` is scoped to a `drivers` row and `PortalController` to
 * a `customers` row. **No path here takes an id that identifies the caller**,
 * and that is the security property rather than a stylistic preference: one
 * forgotten `where` on a shared trip endpoint would show a partner every load
 * in the company, and a wallet endpoint that took a trucker id would show them
 * somebody else's money.
 *
 * The office's half of this module is `TruckerController`. Nothing on this
 * controller can approve anybody, change a rate, or write an earning.
 */
class PartnerController extends ApiController
{
    public function __construct(
        private readonly TruckerService $truckers,
        private readonly JobBoardService $board,
        private readonly WalletService $wallet,
    ) {}

    /**
     * Who the caller is, as a partner.
     *
     * `GET /me` already names the account; this names the *record*, with the
     * standing and the switch on it. The app reads it on open to decide which
     * of three screens to show — waiting for approval, offline, or the board.
     */
    public function profile(Request $request): JsonResponse
    {
        $trucker = $this->me($request);

        return $this->item(
            new TruckerResource($trucker->loadMissing('vehicles.category')),
            ['balance_cents' => $this->wallet->balance($trucker)],
        );
    }

    /**
     * The jobs offered to this partner — never anybody else's, and never
     * unclaimed work. See `JobBoardService::open()`.
     *
     * The handset sends its own position when it has one, which is better than
     * the partner's last reported pin for the obvious reason — it is where they
     * are now rather than where they said they were. It only orders the list
     * now that the list is one person's offers, but a partner holding three of
     * them still wants the nearest first.
     */
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
                // What would actually land with them. Worked out the same way
                // the wallet will work it out at delivery, from the same
                // method, so the board cannot quote one figure and the
                // statement another.
                $trip->price_cents - $this->wallet->commissionOn((int) $trip->price_cents, $rate),
            ))),
            $jobs,
            [
                'commission_bp' => $rate,
                // Why the board is empty, when it is. An empty list and a
                // reason are different answers, and a client with only the
                // first has to guess.
                'can_take_work' => $trucker->canTakeWork(),
                'status' => $trucker->status->value,
            ],
        );
    }

    /** Take a job. First press wins — see `JobBoardService::accept`. */
    public function accept(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->board->accept($this->me($request), $tripId);

        return $this->item(new TripResource($trip), [], 201);
    }

    /** Their own queue: everything taken and not yet closed out. */
    public function trips(Request $request): JsonResponse
    {
        $trips = $this->board->mine($this->me($request));

        return $this->collection(TripResource::collection($trips), $trips);
    }

    /**
     * The run they are on right now.
     *
     * 204 rather than 404 when there is none. A partner between jobs is an
     * ordinary state and not a missing record — the same answer the driver's
     * `trips/current` gives, so the handset needs one idea about it.
     */
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

    /** Roll out. No pre-trip check — see `JobBoardService::start` for why. */
    public function start(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->board->start(
            $this->me($request),
            $tripId,
            $request->string('location')->value() ?: null,
        );

        return $this->item(new TripResource($trip));
    }

    /** Hand over, with the proof. */
    public function deliver(DeliverTripRequest $request, string $tripId): JsonResponse
    {
        // `toProof()` rather than the validated array: the photograph does not
        // survive validation in a shape a service can use, and the request is
        // the one place that already knows the field is there.
        $trip = $this->board->deliver($this->me($request), $tripId, $request->toProof());

        return $this->item(new TripResource($trip));
    }

    /** A photograph for a run already handed over. See `JobBoardService::attachProof`. */
    public function proof(ProofOfDeliveryRequest $request, string $tripId): JsonResponse
    {
        $trip = $this->board->attachProof($this->me($request), $tripId, $request->toProof());

        return $this->item(new TripResource($trip));
    }

    /**
     * The switch, and the position that comes with it.
     *
     * Refused for anybody not yet approved, with the reason — see
     * `TruckerService::setOnline`.
     */
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

    /** Where they are. Sent whenever the handset has a fix worth reporting. */
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

    /**
     * Their money.
     *
     * The balance, the figures above it and the statement, in one call: it is
     * one screen, and three round trips on a handset in a dead spot is three
     * chances to show half of it.
     */
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

    /** Their trucks. */
    public function vehicles(Request $request): JsonResponse
    {
        $vehicles = $this->truckers->vehicles($this->me($request));

        return $this->collection(TruckerVehicleResource::collection($vehicles), $vehicles);
    }

    /** Add a truck, or take one off the road while it is in the shop. */
    public function saveVehicle(TruckerVehicleRequest $request, ?string $vehicleId = null): JsonResponse
    {
        $vehicle = $this->truckers->saveVehicle(
            $this->me($request),
            $request->validated(),
            $vehicleId,
        );

        return $this->item(new TruckerVehicleResource($vehicle), [], $vehicleId === null ? 201 : 200);
    }

    /**
     * The caller's own partner record.
     *
     * A 404 for anybody who has no `truckers` row — an administrator asking for
     * "my jobs" gets the same answer a driver does asking the portal for their
     * invoices, and it is much clearer than an empty list, which reads as a
     * partner with no work.
     */
    private function me(Request $request): Trucker
    {
        $trucker = $this->truckers->forUser((int) $request->user()->getAuthIdentifier());

        abort_if($trucker === null, 404, 'This account is not linked to a trucker record.');

        return $trucker;
    }
}
