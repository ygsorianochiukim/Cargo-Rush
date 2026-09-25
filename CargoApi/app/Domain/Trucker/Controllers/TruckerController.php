<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Controllers;

use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Requests\TruckerVehicleRequest;
use App\Domain\Trucker\Requests\WalletEntryRequest;
use App\Domain\Trucker\Resources\TruckerResource;
use App\Domain\Trucker\Resources\TruckerVehicleResource;
use App\Domain\Trucker\Resources\WalletEntryResource;
use App\Domain\Trucker\Services\TruckerService;
use App\Domain\Trucker\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The office's side of the partner roster — CargoUI's Truckers module.
 *
 * Vetting, assigning, standing down, and settling up. Every one of them is a
 * decision a named person at the haulier makes about somebody outside it, which
 * is why none of it is reachable from the handset: `PartnerController` is the
 * partner's own controller and holds none of these verbs.
 *
 * The permission split follows the one the drivers module already uses.
 * `truckers.view` is the roster and the wallet statement — reading who hauls
 * for you and what they are owed. `truckers.manage` is approving somebody,
 * standing them down and handing money over, all of them consequential and
 * none of them belonging to whoever merely answers the phone.
 *
 * The commission is not set here and has no endpoint on this controller. It is
 * one standing rate for every partner the firm hauls with, on the company, and
 * it is edited on the settings card with the tariff and the tax rates — see
 * `RateBook`.
 */
class TruckerController extends ApiController
{
    public function __construct(
        private readonly TruckerService $truckers,
        private readonly WalletService $wallet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $truckers = $this->truckers->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(TruckerResource::collection($truckers), $truckers);
    }

    public function show(Trucker $trucker): JsonResponse
    {
        return $this->item(
            new TruckerResource($trucker->loadMissing('vehicles.category')),
            // The balance rides along, because the roster's detail screen opens
            // on it — a partner's record and what they are owed are one
            // question at the desk, not two.
            ['balance_cents' => $this->wallet->balance($trucker)],
        );
    }

    /**
     * Who is available to be handed a load right now.
     *
     * Before `truckers/{trucker}` in the route file, so "available" is never
     * read as an id. It is the desk's assign dialog, and it is deliberately
     * not the same as the roster filtered by status — it also requires a truck
     * that is not in the shop, which is a question about the rows underneath.
     */
    public function available(): JsonResponse
    {
        $truckers = $this->truckers->available();

        return $this->collection(TruckerResource::collection($truckers), $truckers);
    }

    /** Approve a registration. The moment somebody becomes usable. */
    public function approve(Trucker $trucker): JsonResponse
    {
        return $this->item(new TruckerResource($this->truckers->approve($trucker)));
    }

    /** Put a partner on hold, with a reason they can read. */
    public function suspend(Request $request, Trucker $trucker): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->item(new TruckerResource(
            $this->truckers->suspend($trucker, $validated['reason'] ?? null),
        ));
    }

    /**
     * Hand a run to a partner.
     *
     * One of only two ways work reaches a contractor — the other is a customer
     * picking them — and the one the desk controls. It is for the load the
     * fleet has no truck free for, or one far enough out that sending its own
     * would cost more than it earns.
     *
     * The run stays the haulier's to invoice, so the partner is credited their
     * share rather than charged a cut. See `TruckerService::assign`.
     */
    public function assign(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'trucker_id' => ['required', 'string', 'exists:truckers,id'],
            'trucker_vehicle_id' => ['nullable', 'string'],
        ]);

        $trucker = $this->truckers->find($validated['trucker_id']);

        return $this->item(new TripResource(
            $this->truckers->assign($trip, $trucker, $validated['trucker_vehicle_id'] ?? null),
        ));
    }

    /** Take a partner back off a run, returning it to the office's queue. */
    public function release(Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->truckers->release($trip)));
    }

    /**
     * One partner's running account.
     *
     * The figures and the statement together, the same shape the handset's own
     * wallet call returns — so the office tab and the partner's screen are
     * demonstrably reading the same numbers, which is the point of showing them
     * at all.
     */
    public function wallet(Request $request, Trucker $trucker): JsonResponse
    {
        $entries = $this->wallet->statement(
            $trucker,
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
            $this->perPage($request, 100),
        );

        return $this->payload([
            ...$this->wallet->summary($trucker),
            'entries' => WalletEntryResource::collection($entries)->resolve(),
        ], [
            'trucker_id' => $trucker->getKey(),
            'trucker_name' => $trucker->name,
            'commission_bp' => $trucker->commissionRateBp(),
        ]);
    }

    /**
     * Settle up: pay out, take a remittance, or correct something.
     *
     * One endpoint for the three because it is one form at the desk. Which of
     * them it is decides the direction and what it may not exceed, and both are
     * the service's to enforce — they depend on the current balance, which is a
     * question about the database rather than about the payload.
     */
    public function settle(WalletEntryRequest $request, Trucker $trucker): JsonResponse
    {
        $data = $request->validated();
        $recordedBy = (int) $request->user()->getAuthIdentifier();
        $occurredOn = isset($data['occurred_on']) ? Carbon::parse((string) $data['occurred_on']) : null;
        // Empty means every outstanding run, which is the ordinary case.
        $entryIds = $request->entryIds();

        $entry = match (WalletEntryKind::from($data['kind'])) {
            WalletEntryKind::Payout => $this->wallet->payOut(
                $trucker,
                $entryIds,
                $data['reference'] ?? null,
                $data['note'] ?? null,
                $recordedBy,
                $occurredOn,
                $data['method'] ?? WalletService::DEFAULT_METHOD,
                (bool) ($data['cleared'] ?? false),
            ),
            WalletEntryKind::Remittance => $this->wallet->remit(
                $trucker,
                $entryIds,
                $data['reference'] ?? null,
                $data['note'] ?? null,
                $recordedBy,
                $occurredOn,
                $data['method'] ?? WalletService::DEFAULT_METHOD,
                (bool) ($data['cleared'] ?? false),
            ),
            // The one kind that still carries a figure, and the one whose sign
            // is the caller's — so it is passed through exactly as typed.
            default => $this->wallet->adjust(
                $trucker,
                (int) $data['amount_cents'],
                (string) ($data['note'] ?? ''),
                $recordedBy,
                $occurredOn,
            ),
        };

        return $this->item(
            new WalletEntryResource($entry),
            ['balance_cents' => $this->wallet->balance($trucker->refresh())],
            201,
        );
    }

    /**
     * Confirm a payment has landed.
     *
     * The second half of paying somebody, and the moment the balance finally
     * falls. Its own endpoint rather than a flag on the settle, because they
     * are two facts on two different days: the office sends a transfer on
     * Monday and sees it clear on Tuesday, and a wallet that collapsed the
     * two would have told the partner on Monday that the money had arrived.
     *
     * `truckers.manage`, like every other verb that moves money. Confirming
     * your own payment arrived is not a thing the person being paid should do
     * — see the route file.
     */
    public function confirmPayment(Request $request, Trucker $trucker, string $entryId): JsonResponse
    {
        $settlement = WalletEntry::query()
            ->where('trucker_id', $trucker->getKey())
            ->find($entryId);

        // Scoped to this partner, so an id from somebody else's statement is
        // a 404 rather than a payment confirmed against the wrong account.
        abort_if($settlement === null, 404, 'That payment is not on this trucker\'s account.');

        $entry = $this->wallet->markLanded(
            $settlement,
            (int) $request->user()->getAuthIdentifier(),
        );

        return $this->item(
            new WalletEntryResource($entry),
            ['balance_cents' => $this->wallet->balance($trucker->refresh())],
        );
    }

    /** A partner's trucks, from the desk. */
    public function vehicles(Trucker $trucker): JsonResponse
    {
        $vehicles = $this->truckers->vehicles($trucker);

        return $this->collection(TruckerVehicleResource::collection($vehicles), $vehicles);
    }

    public function saveVehicle(
        TruckerVehicleRequest $request,
        Trucker $trucker,
        ?string $vehicleId = null,
    ): JsonResponse {
        $vehicle = $this->truckers->saveVehicle($trucker, $request->validated(), $vehicleId);

        return $this->item(new TruckerVehicleResource($vehicle), [], $vehicleId === null ? 201 : 200);
    }
}
