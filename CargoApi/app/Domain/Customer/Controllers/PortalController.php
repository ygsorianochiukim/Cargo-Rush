<?php

declare(strict_types=1);

namespace App\Domain\Customer\Controllers;

use App\Domain\Customer\Requests\DeliveryRequestRequest;
use App\Domain\Customer\Resources\PortalInvoiceResource;
use App\Domain\Customer\Resources\PortalTripResource;
use App\Domain\Customer\Services\PortalService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\NearbyCarriersRequest;
use App\Domain\Tenancy\Resources\CarrierResource;
use App\Domain\Trucker\Services\HaulerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalController extends ApiController
{
    public function __construct(
        private readonly PortalService $portal,
        private readonly HaulerDirectory $haulers,
    ) {}

    public function carriers(NearbyCarriersRequest $request): JsonResponse
    {
        $listings = $this->portal->carriers(
            $this->user($request),
            $request->point(),
            $request->radiusKm(),
            $request->search(),
        );

        return $this->collection(CarrierResource::collection($listings), $listings);
    }

    public function haulers(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $fleet = $this->portal->carrier($user, null);

        $listings = $this->haulers->near(
            $fleet,
            $request->has('lat') ? (float) $request->input('lat') : null,
            $request->has('lng') ? (float) $request->input('lng') : null,
        );

        return $this->payload($listings->all(), ['fleet_id' => $fleet->getKey()]);
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->payload($this->portal->summary($this->user($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $trips = $this->portal->requests($this->user($request), $this->filters($request));

        return $this->collection(PortalTripResource::collection($trips), $trips);
    }

    public function show(Request $request, string $tripId): JsonResponse
    {
        return $this->item(new PortalTripResource($this->portal->request($this->user($request), $tripId)));
    }

    public function store(DeliveryRequestRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $carrier = $this->portal->carrier($user, $request->carrierId());
        $account = $this->portal->accountFor($user, $carrier);

        $trip = $this->portal->submit(
            $account,
            $request->toData($account->id, (int) $user->id),
            $request->truckerId(),
        );

        return $this->item(new PortalTripResource($trip), status: 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = $this->portal->invoices($this->user($request));

        return $this->collection(PortalInvoiceResource::collection($invoices), $invoices);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
