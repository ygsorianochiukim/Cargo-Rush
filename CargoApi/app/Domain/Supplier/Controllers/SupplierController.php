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

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->suppliers->delete($supplier);

        return $this->noContent();
    }

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
