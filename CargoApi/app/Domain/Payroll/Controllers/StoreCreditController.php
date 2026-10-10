<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Payroll\Requests\StoreCreditRequest;
use App\Domain\Payroll\Resources\StoreCreditResource;
use App\Domain\Payroll\Services\StoreCreditService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class StoreCreditController extends ApiController
{
    public function __construct(private readonly StoreCreditService $store) {}

    public function index(Employee $employee): JsonResponse
    {
        $rows = $this->store->ledgerFor($employee->getKey());

        return $this->collection(
            StoreCreditResource::collection($rows),
            $rows,
            meta: [
                'balance_cents' => $this->store->balanceFor($employee->getKey()),
                'next_deduction_cents' => $this->store->deductionFor(
                    $employee,
                    $this->store->balanceFor($employee->getKey()),
                ),
            ],
        );
    }

    public function store(StoreCreditRequest $request, Employee $employee): JsonResponse
    {
        return $this->item(
            new StoreCreditResource(
                $this->store->record($employee, $request->validated(), $request->user()?->id),
            ),
            status: 201,
        );
    }

    public function destroy(StoreCredit $storeCredit): JsonResponse
    {
        $this->store->forget($storeCredit);

        return $this->noContent();
    }
}
