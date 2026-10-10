<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:0'],

            'pay_basis' => ['sometimes', Rule::enum(PayBasis::class)],

            'tier' => ['sometimes', Rule::in(array_column(EmploymentType::tiers(), 'value'))],

            'effective_from' => ['sometimes', 'date'],

            'reason' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.integer' => 'Send the pay in centavos as a whole number, not pesos.',
        ];
    }
}
