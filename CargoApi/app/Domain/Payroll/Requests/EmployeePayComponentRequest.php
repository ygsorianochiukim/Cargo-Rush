<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

/**
 * Giving one person one component, over a stretch of time.
 *
 * `employee_id` and `pay_component_id` are required on create and absent from
 * the update rules entirely — moving an assignment to a different person is not
 * an edit, it is a mistake corrected by deleting one row and adding another.
 * Allowing it would let a PATCH silently reassign somebody's loan repayment.
 */
class EmployeePayComponentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = [
            /**
             * The amounts, where this person's differ from the catalogue's.
             *
             * Explicitly nullable, and that is how an override is *removed*:
             * sending null puts the person back on the component's own figure,
             * which a form otherwise has no way to express.
             */
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],

            'effective_from' => [$this->requiredOnCreate(), 'date'],
            /**
             * Open-ended unless somebody says otherwise.
             *
             * `after_or_equal` rather than `after`: a one-day window is odd but
             * it is not wrong, and refusing it would send somebody to the
             * database to fix a typo.
             */
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],

            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'notes' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->creating()) {
            /**
             * Both scoped to the caller's own company.
             *
             * `exists` queries the table directly and so runs **outside** the
             * tenant scope every read normally sits behind — the company clause
             * is what puts it back. Without it, an id belonging to another firm
             * would pass validation and then create a row pointing at a record
             * this company cannot see: a dangling assignment, and a small
             * confirmation that the neighbour's employee exists.
             */
            $company = app(Tenant::class)->id();

            $rules['employee_id'] = [
                'required', 'string',
                Rule::exists('employees', 'id')->where('company_id', $company)->whereNull('deleted_at'),
            ];
            $rules['pay_component_id'] = [
                'required', 'string',
                Rule::exists('pay_components', 'id')->where('company_id', $company)->whereNull('deleted_at'),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'effective_to.after_or_equal' => 'An assignment cannot end before it starts.',
            'pay_component_id.exists' => 'That is not a component on this firm’s list.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return $this->safe()->only([
            'employee_id', 'pay_component_id', 'amount_cents', 'rate_bp',
            'effective_from', 'effective_to', 'status', 'notes',
        ]);
    }
}
