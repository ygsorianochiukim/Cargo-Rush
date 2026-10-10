<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Shared\Enums\StoreCreditKind;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreCreditRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', Rule::enum(StoreCreditKind::class)],

            'amount_cents' => ['required', 'integer', 'min:1'],

            'description' => ['nullable', 'string', 'max:160'],

            'outlet' => ['nullable', 'string', 'max:60'],

            'charged_on' => ['sometimes', 'date'],

            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.integer' => 'Send the amount in centavos as a whole number, not pesos.',
            'amount_cents.min' => 'A row has to move the balance by something.',
        ];
    }
}
