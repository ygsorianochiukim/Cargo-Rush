<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One row of the firm's salary structure.
 *
 * The labels and the sentence ride along with the values, as they do on the
 * company's deduction schedule, so a screen offering the choice does not keep
 * its own copy of what "monthly, all on the second cutoff" means. Two clients
 * paraphrasing a payroll rule is two chances to paraphrase it wrongly.
 *
 * @mixin PayComponent
 */
class PayComponentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'sign' => $this->kind->sign(),

            'basis' => $this->basis->value,
            'basis_label' => $this->basis->label(),
            'amount_cents' => $this->amount_cents,
            'rate_bp' => $this->rate_bp,

            'schedule' => $this->schedule->value,
            'schedule_label' => $this->schedule->label(),
            'schedule_detail' => $this->schedule->detail(),
            /** Is the amount a month's worth, or one payslip's? */
            'is_monthly' => $this->schedule->isMonthly(),

            /**
             * Whether this goes into the tax base.
             *
             * Reported through the model rather than off the column, so a
             * deduction always reads false — the flag is meaningless there and
             * a screen showing "taxable" beside a uniform deduction would be
             * asking somebody to answer a question that has no answer.
             */
            'taxable' => $this->isTaxable(),

            'status' => $this->status?->value,
            'position' => $this->position,
            'notes' => $this->notes,
            'currency' => 'PHP',

            /**
             * How many people are on it.
             *
             * Only where it has been counted — this is what tells an office
             * that deleting a component will retire it instead, and it would be
             * a query per row if it were loaded unconditionally.
             */
            'assignment_count' => $this->whenCounted('assignments'),

            ...$this->stamps(),
        ];
    }
}
