<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Controllers;

use App\Domain\Billing\Resources\InvoiceResource;
use App\Domain\Finance\Resources\ExpenseResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Requests\SupplierRequest;
use App\Domain\Supplier\Resources\SupplierResource;
use App\Domain\Supplier\Services\SupplierService;
use App\Domain\Vehicle\Resources\MaintenanceJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Suppliers — who the fleet buys from, and what it has spent there. */
class SupplierController extends ApiController
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->suppliers->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(SupplierResource::collection($page), $page);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->item(new SupplierResource($supplier));
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        return $this->item(
            new SupplierResource($this->suppliers->create($request->toData())),
            status: 201,
        );
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        return $this->item(new SupplierResource($this->suppliers->update($supplier, $request->toData())));
    }

    /**
     * Remove one.
     *
     * A soft delete, so the expenses, services and bills already filed against
     * it keep pointing at a row that still resolves. An office that has stopped
     * buying from somebody usually wants `status: inactive` instead — it takes
     * them out of the picker and leaves them in the history.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->suppliers->delete($supplier);

        return $this->noContent();
    }

    /** What has been bought from one — the three places its money shows up. */
    public function history(Supplier $supplier): JsonResponse
    {
        $history = $this->suppliers->history($supplier);

        return $this->payload([
            'expenses' => ExpenseResource::collection($history['expenses'])->resolve(),
            'maintenance' => MaintenanceJobResource::collection($history['maintenance'])->resolve(),
            'bills' => InvoiceResource::collection($history['bills'])->resolve(),
        ]);
    }
}
