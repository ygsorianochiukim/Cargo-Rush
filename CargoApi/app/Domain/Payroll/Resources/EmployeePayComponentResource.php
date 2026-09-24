<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Shared\Enums\PayComponentBasis;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One person's assignment of one component.
 *
 * The component comes with it rather than as an id to go and fetch: the screen
 * this feeds is a list of what somebody is paid, and a row reading `01J8…` is
 * not that. The **effective** amount is worked out here too — the override
 * where there is one, the catalogue's figure where there is not — because
 * "which of these two numbers is actually in force" is a rule the API owns and
 * not one each client should re-derive.
 *
 * @mixin EmployeePayComponent
 */
class EmployeePayComponentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $component = $this->component;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'pay_component_id' => $this->pay_component_id,

            'component' => $component === null ? null : new PayComponentResource($component),

            /**
             * This person's own figures, or null where they follow the
             * catalogue.
             *
             * Null is meaningful and is not the same as zero: it says "whatever
             * the component says", which is what keeps a firm-wide raise to one
             * edited row. A client turning null into 0 on the way into a form
             * would turn every such assignment into a zero override the next
             * time somebody saved it.
             */
            'amount_cents' => $this->amount_cents,
            'rate_bp' => $this->rate_bp,

            /**
             * The monthly peso figure in force — override or catalogue.
             *
             * **Null for a percentage component**, deliberately, because there
             * is no answer without a salary and this row does not carry one.
             * Reporting a percentage as ₱0.00 would be worse than reporting
             * nothing: an office scanning a list of allowances would read it as
             * a component that pays nothing, and go looking for the bug. The
             * rate is in `rate_bp` beside it, and what it comes to for a given
             * person is a question the payslip answers.
             */
            'effective_amount_cents' => $component?->basis === PayComponentBasis::Fixed
                ? ($this->amount_cents ?? (int) $component->amount_cents)
                : null,
            'effective_rate_bp' => $component?->basis === PayComponentBasis::PercentOfBasic
                ? ($this->rate_bp ?? (int) $component->rate_bp)
                : null,
            'overrides_amount' => $this->amount_cents !== null || $this->rate_bp !== null,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),

            'status' => $this->status?->value,
            'notes' => $this->notes,
            'currency' => 'PHP',

            ...$this->stamps(),
        ];
    }
}
