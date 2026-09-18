<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin StoreCredit
 */
class StoreCreditResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            // Which way it moves the balance, composed here so a running total
            // on screen and the one payroll deducts cannot disagree about it.
            'sign' => $this->kind->sign(),
            'amount_cents' => $this->amount_cents,
            'signed_cents' => $this->signedCents(),
            'description' => $this->description,
            'outlet' => $this->outlet,
            'charged_on' => $this->charged_on?->toDateString(),
            /**
             * Was this written by payroll rather than the storekeeper?
             *
             * Sent so a client can grey out the delete rather than offer one
             * the API will refuse: a repayment taken off an approved payslip is
             * part of that payslip, and removing it here would leave the tab
             * and the payslip disagreeing.
             */
            'pay_run_line_id' => $this->pay_run_line_id,
            'from_payroll' => $this->isFromPayroll(),
            'recorded_by' => $this->recordedBy?->name,
            'notes' => $this->notes,

            ...$this->stamps(),
        ];
    }
}
