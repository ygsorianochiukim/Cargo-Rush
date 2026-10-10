<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Finance\DTO\LedgerEntryData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

class LedgerEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'truck_id' => [$required, 'string', 'exists:trucks,id'],
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],

            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],

            'helpers' => ['sometimes', 'array', 'max:5'],
            'helpers.*.driver_id' => ['nullable', 'string', 'exists:drivers,id', 'different:driver_id'],
            'helpers.*.salary_cents' => ['required', 'integer', 'min:0'],

            'date' => [$required, 'date'],
            'trip_income_cents' => ['sometimes', 'integer', 'min:0'],
            'fuel_cents' => ['sometimes', 'integer', 'min:0'],
            'driver_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'helper_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'maintenance_cents' => ['sometimes', 'integer', 'min:0'],
            'allowance_cents' => ['sometimes', 'integer', 'min:0'],
            'route' => ['nullable', 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'total_expenses_cents' => ['prohibited'],
            'net_income_cents' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'helpers.*.driver_id.different' => 'The driver and a helper cannot be the same person.',
            'helpers.max' => 'A day can carry at most five helpers.',
            'total_expenses_cents.prohibited' => 'Total expenses is derived from the five expense fields; do not send it.',
            'net_income_cents.prohibited' => 'Net income is derived from income minus expenses; do not send it.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $named = array_filter(array_column((array) $this->input('helpers', []), 'driver_id'));

                if (count($named) !== count(array_unique($named))) {
                    $validator->errors()->add('helpers', 'The same helper is named twice.');
                }
            },
        ];
    }

    public function toData(): LedgerEntryData
    {
        return LedgerEntryData::fromArray($this->validated());
    }
}
