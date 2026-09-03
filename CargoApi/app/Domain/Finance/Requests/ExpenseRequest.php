<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Finance\DTO\ExpenseData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'category_id' => [$required, 'string', 'exists:expense_categories,id'],
            // Null is fleet overhead, not a missing value — the office rent
            // belongs to the period, not to a truck.
            'truck_id' => ['nullable', 'string', 'exists:trucks,id'],
            'trip_id' => ['nullable', 'string', 'exists:trips,id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            'date' => [$required, 'date'],
            'amount_cents' => [$required, 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payee' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => [
                'sometimes',
                Rule::in([
                    StatusValue::Active->value,
                    StatusValue::Pending->value,
                    StatusValue::Cancelled->value,
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.integer' => 'Send the amount in centavos as a whole number, not pesos.',
            'status.in' => 'An expense is active, pending approval, or cancelled.',
        ];
    }

    public function toData(): ExpenseData
    {
        return ExpenseData::fromArray($this->validated());
    }
}
