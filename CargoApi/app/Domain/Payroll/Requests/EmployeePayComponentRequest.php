<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class EmployeePayComponentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = [
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],

            'effective_from' => [$this->requiredOnCreate(), 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],

            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'notes' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->creating()) {
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

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return $this->safe()->only([
            'employee_id', 'pay_component_id', 'amount_cents', 'rate_bp',
            'effective_from', 'effective_to', 'status', 'notes',
        ]);
    }
}
