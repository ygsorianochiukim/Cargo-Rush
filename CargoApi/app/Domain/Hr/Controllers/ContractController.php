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

/**
 * What one person has been paid, over time.
 *
 * Under the **payroll** permissions rather than HR's, for the same reason the
 * pay components and the store tab beside it are: what somebody earns is pay
 * information rather than roster information. Reading is `payroll.view` and
 * writing is `payroll.manage`, so an HR officer can see what somebody is on
 * while agreeing a new figure stays with whoever runs the payroll.
 *
 * There is no update and no delete here, and that is the design rather than an
 * omission. A rise appends; a correction appends; a change of basis appends.
 * The row a pay run was built from has to still be there afterwards, or a draft
 * rebuilt next week quietly pays a different figure for the same fortnight.
 */
class ContractController extends ApiController
{
    public function __construct(private readonly ContractService $contracts) {}

    /**
     * The whole history, newest first, and which row is paying today.
     *
     * The current one is named in `meta` rather than flagged per row, because
     * it is a fact about the list: the rule is not "the newest" but "the latest
     * one that has started", and a row dated forward is on the list without
     * paying yet.
     */
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

    /**
     * Put them on a new figure from a given day.
     *
     * The basis and the tier carry over from the agreement in force unless the
     * caller states them, because the everyday case is a rise and restating the
     * rest of somebody's terms to change one number is how the rest of it
     * eventually gets restated wrong.
     */
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

    /**
     * Take back a row written by mistake.
     *
     * The one thing that is not an append, and it is deliberately narrow: this
     * is for the agreement typed with a digit missing five minutes ago, not for
     * rewriting what somebody was on last year. It soft-deletes, so the row is
     * still findable by anybody asking what happened.
     */
    public function destroy(Request $request, Employee $employee, Contract $contract): JsonResponse
    {
        abort_unless($contract->employee_id === $employee->getKey(), 404);

        $contract->delete();

        return $this->noContent();
    }
}
