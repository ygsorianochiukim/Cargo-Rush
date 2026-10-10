<?php

declare(strict_types=1);

namespace App\Domain\Hr\Controllers;

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Requests\ContractRequest;
use App\Domain\Hr\Resources\ContractResource;
use App\Domain\Hr\Services\ContractService;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends ApiController
{
    public function __construct(private readonly ContractService $contracts) {}

    public function index(Employee $employee): JsonResponse
    {
        $rows = $employee->contracts()->with('author:id,name')->get();

        return $this->collection(
            ContractResource::collection($rows),
            $rows,
            meta: [
                'current_contract_id' => $employee->contractOn()?->getKey(),
                'amount_cents' => $employee->amountCentsOn(),
                'pay_basis' => $employee->payBasisOn()->value,
            ],
        );
    }

    public function store(ContractRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->validated();
        $current = $employee->contractOn();

        $contract = $this->contracts->open(
            $employee,
            isset($data['pay_basis'])
                ? PayBasis::from($data['pay_basis'])
                : ($current?->pay_basis ?? PayBasis::Monthly),
            (int) $data['amount_cents'],
            $data['effective_from'] ?? null,
            $data['reason'] ?? null,
            isset($data['tier'])
                ? EmploymentType::from($data['tier'])
                : $current?->tier,
            $request->user(),
        );

        return $this->item(new ContractResource($contract->load('author:id,name')), status: 201);
    }

    public function destroy(Request $request, Employee $employee, Contract $contract): JsonResponse
    {
        abort_unless($contract->employee_id === $employee->getKey(), 404);

        $contract->delete();

        return $this->noContent();
    }
}
