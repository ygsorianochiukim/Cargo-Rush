<?php

declare(strict_types=1);

namespace App\Domain\Trip\Controllers;

use App\Domain\Customer\DTO\CustomerData;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Requests\ConfirmTripRequest;
use App\Domain\Trip\Requests\DeliverTripRequest;
use App\Domain\Trip\Requests\TripRequest;
use App\Domain\Trip\Resources\TripResource;
use App\Domain\Trip\Services\TripCostService;
use App\Domain\Trip\Services\TripHistoryService;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trip\Services\TripTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TripController extends ApiController
{
    public function __construct(
        private readonly TripService $trips,
        private readonly CustomerService $customers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->trips->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(TripResource::collection($page), $page);
    }

    public function show(Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($trip));
    }

    public function history(Trip $trip, TripHistoryService $history): JsonResponse
    {
        return $this->payload($history->for($trip));
    }

    public function costs(Trip $trip, TripCostService $costs): JsonResponse
    {
        return $this->payload($costs->for($trip));
    }

    public function ticket(Trip $trip, TripTicketService $tickets): JsonResponse
    {
        return $this->payload($tickets->build($trip));
    }

    public function store(TripRequest $request): JsonResponse
    {
        $trip = DB::transaction(function () use ($request): Trip {
            $this->registerNewCustomer($request);

            if (! $this->isPastDelivery($request)) {
                return $this->trips->create($request->toData());
            }

            return $this->recordPayment($request, $this->recordDelivery($request, $this->trips->create($this->asAssigned($request))));
        });

        return $this->item(new TripResource($trip), status: 201);
    }

    public function update(TripRequest $request, Trip $trip): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $trip): Trip {
            $this->registerNewCustomer($request);

            if (! $this->isPastDelivery($request) || $trip->status === StatusValue::Delivered) {
                return $this->recordPayment($request, $this->trips->update($trip, $request->toData()));
            }

            return $this->recordPayment($request, $this->recordDelivery($request, $this->trips->update($trip, $this->asAssigned($request))));
        });

        return $this->item(new TripResource($updated));
    }

    private function registerNewCustomer(TripRequest $request): void
    {
        $fields = $request->newCustomer();

        if ($fields === null) {
            return;
        }

        $existing = Customer::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($fields['name']))])
            ->value('id');

        $request->useCustomer($existing ?? $this->customers->create(CustomerData::fromArray($fields))->id);
    }

    private function isPastDelivery(TripRequest $request): bool
    {
        return $request->input('status') === StatusValue::Delivered->value;
    }

    private function asAssigned(TripRequest $request): TripData
    {
        return TripData::fromArray([...$request->payload(), 'status' => StatusValue::Assigned->value]);
    }

    private function recordDelivery(TripRequest $request, Trip $trip): Trip
    {
        $at = $request->filled('delivered_at')
            ? Carbon::parse($request->input('delivered_at'))
            : $trip->scheduled_at;

        return $this->trips->complete(
            $trip,
            new ProofData(receiver_name: $request->string('receiver_name')->value() ?: 'Recorded by the office'),
            $at,
        );
    }

    private function recordPayment(TripRequest $request, Trip $trip): Trip
    {
        $payment = $request->payment();

        if ($payment !== null && $trip->status === StatusValue::Delivered) {
            $this->trips->recordPayment($trip, $payment, $request->user()?->id);
        }

        return $trip->refresh();
    }

    public function destroy(Trip $trip): JsonResponse
    {
        $this->trips->delete($trip);

        return $this->noContent();
    }

    public function confirm(ConfirmTripRequest $request, Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->trips->confirm($trip, $request->toData())));
    }

    public function dispatchTrip(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'location' => ['required', 'string', 'max:160'],
        ]);

        $this->trips->dispatch($trip, $validated['location']);

        return $this->item(new TripResource($trip->refresh()));
    }

    public function complete(DeliverTripRequest $request, Trip $trip): JsonResponse
    {
        return $this->item(new TripResource($this->trips->complete($trip, $request->toProof())));
    }
}
