<?php

declare(strict_types=1);

namespace App\Domain\Customer\Controllers;

use App\Domain\Billing\Resources\InvoiceResource;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Requests\DeliveryRequestRequest;
use App\Domain\Customer\Services\PortalService;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Resources\TripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own screens — what `cargoApp` shows when the account signing
 * in is a customer rather than a driver.
 *
 * The exact counterpart of `DriverTripController`, and separate from
 * `TripController` for the same reason: every endpoint here is scoped to the
 * caller's `customers` row and carries no id that could be changed into
 * somebody else's. The office reads the same trips through the module
 * controller, with the permissions that come with it.
 */
class PortalController extends ApiController
{
    public function __construct(private readonly PortalService $portal) {}

    /** The counts and the two money figures the customer home screen leads with. */
    public function summary(Request $request): JsonResponse
    {
        return $this->payload($this->portal->summary($this->customer($request)));
    }

    /** Everything this customer has asked for, newest first. */
    public function index(Request $request): JsonResponse
    {
        $trips = $this->portal->requests($this->customer($request), $this->filters($request));

        return $this->collection(TripResource::collection($trips), $trips);
    }

    /**
     * One of their own deliveries.
     *
     * Another firm's is a 404, not a 403: telling somebody a trip exists but
     * is not theirs confirms the trip exists, which is itself the leak. The
     * route binding has already resolved it, so the check here is ownership
     * and nothing else.
     */
    public function show(Request $request, Trip $trip): JsonResponse
    {
        abort_unless(
            $trip->customer_id === $this->customer($request)->id,
            404,
            'No delivery of yours with that reference.',
        );

        return $this->item(new TripResource($trip));
    }

    /**
     * Ask for a pickup.
     *
     * Comes back as the trip it created, `pending`, with its reference and the
     * price it was quoted at — so the customer leaves the form knowing both
     * what to quote on the phone and what it will cost, instead of waiting to
     * be told.
     */
    public function store(DeliveryRequestRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        $trip = $this->portal->submit(
            $customer,
            $request->toData($customer->id, (int) $request->user()->id),
        );

        return $this->item(new TripResource($trip), status: 201);
    }

    /** Their receivables — what is owed, and what has been settled. */
    public function invoices(Request $request): JsonResponse
    {
        $invoices = $this->portal->invoices($this->customer($request));

        return $this->collection(InvoiceResource::collection($invoices), $invoices);
    }

    /**
     * The `customers` row behind the login.
     *
     * A driver or a back-office user calling these has none, and a 404 says
     * that plainly — the same answer `DriverTripController` gives an
     * administrator asking for "my trips". An empty list would read as a
     * customer with no deliveries, which is a different and much more
     * confusing thing to be told.
     */
    private function customer(Request $request): Customer
    {
        $customer = $request->user()->customer;

        abort_if($customer === null, 404, 'This account is not linked to a customer record.');

        return $customer;
    }
}
