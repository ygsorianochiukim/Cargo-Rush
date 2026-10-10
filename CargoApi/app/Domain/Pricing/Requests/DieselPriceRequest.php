<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class DieselPriceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'effective_on' => ['sometimes', 'date'],
            'price_per_litre_cents' => ['required', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'price_per_litre_cents.integer' => 'Send the pump price in centavos per litre as a whole number.',
        ];
    }
}
