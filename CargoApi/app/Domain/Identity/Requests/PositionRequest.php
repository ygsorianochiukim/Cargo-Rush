<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PositionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:160'],
            'drives' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],

            'pay_basis' => ['sometimes', Rule::enum(PayBasis::class)],
            'trainee_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'probationary_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'regular_amount_cents' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'trainee_amount_cents.integer' => 'Send the trainee rate in centavos as a whole number, not pesos.',
            'probationary_amount_cents.integer' => 'Send the probationary rate in centavos as a whole number, not pesos.',
            'regular_amount_cents.integer' => 'Send the regular rate in centavos as a whole number, not pesos.',
        ];
    }
}
