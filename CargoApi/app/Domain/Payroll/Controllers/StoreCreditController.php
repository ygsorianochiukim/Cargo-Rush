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

/**
 * The store tab — the mini-mart *pautang*, per person.
 *
 * A charge is goods taken against pay; a payment is money back. The balance is
 * the difference, and payroll takes it off at the cutoff.
 *
 * Under the **payroll** permissions rather than HR's, for the reason the pay
 * components beside it are: what somebody owes the firm and what comes off
 * their payslip is pay information, and the line this system draws puts that
 * with payroll rather than with the roster.
 */
class StoreCreditController extends ApiController
{
    public function __construct(private readonly StoreCreditService $store) {}

    /**
     * One person's tab: the balance, and the rows behind it.
     *
     * Both, because they answer different questions. The balance is what the
     * next payslip will take; the rows are what it is made of, and a balance
     * with nothing behind it is a figure somebody has to take on trust.
     */
    public function index(Employee $employee): JsonResponse
    {
        $rows = $this->store->ledgerFor($employee->getKey());

        return $this->collection(
            StoreCreditResource::collection($rows),
            $rows,
            meta: [
                'balance_cents' => $this->store->balanceFor($employee->getKey()),
                /**
                 * What the next payslip would take, at today's balance.
                 *
                 * Shown so the cap is something the office can see working
                 * rather than a number they have to reason about. A run's own
                 * figure is worked out as at its period end and may differ —
                 * the payslip says which period it is for.
                 */
                'next_deduction_cents' => $this->store->deductionFor(
                    $employee,
                    $this->store->balanceFor($employee->getKey()),
                ),
                'cap_cents' => (int) $employee->store_deduction_cap_cents,
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

    /**
     * Remove a row.
     *
     * Refused for a repayment payroll wrote — that figure is on a payslip
     * somebody has been handed. The way to undo one is a correcting charge,
     * which is what a ledger is for.
     */
    public function destroy(StoreCredit $storeCredit): JsonResponse
    {
        $this->store->forget($storeCredit);

        return $this->noContent();
    }
}
