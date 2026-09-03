<?php

declare(strict_types=1);

namespace App\Domain\Customer\Controllers;

use App\Domain\Billing\Resources\InvoiceResource;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Requests\CustomerRequest;
use App\Domain\Customer\Resources\CustomerResource;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Finance\Resources\LedgerEntryResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Resources\TripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer Management — records, transaction history, feedback. */
class CustomerController extends ApiController
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->customers->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(CustomerResource::collection($page), $page);
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->item(new CustomerResource($customer));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return $this->item(new CustomerResource($this->customers->create($request->toData())), status: 201);
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        return $this->item(new CustomerResource($this->customers->update($customer, $request->toData())));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customers->delete($customer);

        return $this->noContent();
    }

    /**
     * What one customer has behind them — the "history" tab.
     *
     * Trips are what was hauled, invoices what was billed, and the ledger rows
     * what the work earned and cost.
     */
    public function history(Customer $customer): JsonResponse
    {
        $history = $this->customers->history($customer);

        return $this->payload([
            'trips' => TripResource::collection($history['trips'])->resolve(),
            'invoices' => InvoiceResource::collection($history['invoices'])->resolve(),
            'ledger_entries' => LedgerEntryResource::collection($history['ledger_entries'])->resolve(),
        ]);
    }
}
