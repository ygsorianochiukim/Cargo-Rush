<?php

declare(strict_types=1);

namespace App\Domain\Billing\Controllers;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Requests\InvoiceRequest;
use App\Domain\Billing\Resources\InvoiceResource;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Billing & Invoice — receivables, payables, payment history. */
class BillingController extends ApiController
{
    public function __construct(private readonly BillingService $billing) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->billing->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(InvoiceResource::collection($page), $page);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($invoice));
    }

    public function store(InvoiceRequest $request): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->create($request->toData())), status: 201);
    }

    public function update(InvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->update($invoice, $request->toData())));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->billing->delete($invoice);

        return $this->noContent();
    }

    /** Marking one paid, as a verb rather than a status PATCH. */
    public function settle(Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->settle($invoice)));
    }

    /** Receivable against payable — the two numbers the page leads with. */
    public function totals(): JsonResponse
    {
        return $this->payload($this->billing->totals());
    }
}
