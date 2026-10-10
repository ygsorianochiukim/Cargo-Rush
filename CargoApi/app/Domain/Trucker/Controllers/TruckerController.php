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
            new TruckerResource($trucker->loadMissing(['vehicles.category', 'drivers'])),
            ['balance_cents' => $this->wallet->balance($trucker)],
        );
    }

    public function available(): JsonResponse
    {
        $truckers = $this->truckers->available();

        return $this->collection(TruckerResource::collection($truckers), $truckers);
    }

    public function approve(Trucker $trucker): JsonResponse
    {
        return $this->item(new TruckerResource($this->truckers->approve($trucker)));
    }

    public function suspend(Request $request, Trucker $trucker): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->item(new TruckerResource(
            $this->truckers->suspend($trucker, $validated['reason'] ?? null),
        ));
    }

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

    public function release(Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->truckers->release($trip)));
    }

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

    public function settle(WalletEntryRequest $request, Trucker $trucker): JsonResponse
    {
        $data = $request->validated();
        $recordedBy = (int) $request->user()->getAuthIdentifier();
        $occurredOn = isset($data['occurred_on']) ? Carbon::parse((string) $data['occurred_on']) : null;
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

    public function confirmPayment(Request $request, Trucker $trucker, string $entryId): JsonResponse
    {
        $settlement = WalletEntry::query()
            ->where('trucker_id', $trucker->getKey())
            ->find($entryId);

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
        $vehicle = $this->truckers->saveVehicle(
            $trucker,
            $request->details(),
            $vehicleId,
            $request->photos(),
            $request->user(),
        );

        return $this->item(new TruckerVehicleResource($vehicle), [], $vehicleId === null ? 201 : 200);
    }

    public function verifyVehicle(Request $request, Trucker $trucker, string $vehicleId): JsonResponse
    {
        return $this->item(new TruckerVehicleResource(
            $this->truckers->verifyVehicle($trucker, $vehicleId, $request->user()),
        ));
    }

    public function rejectVehicle(Request $request, Trucker $trucker, string $vehicleId): JsonResponse
    {
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Say what is wrong, so the trucker knows what to send again.'],
        );

        return $this->item(new TruckerVehicleResource(
            $this->truckers->rejectVehicle($trucker, $vehicleId, $validated['reason']),
        ));
    }
}
