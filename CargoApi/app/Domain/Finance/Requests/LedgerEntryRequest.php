<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Finance\DTO\LedgerEntryData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class LedgerEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'truck_id' => [$required, 'string', 'exists:trucks,id'],
            // Optional on purpose. A day is per truck, not per customer: it
            // can be the company's own freight, or several customers' work in
            // one row. Naming one where it applies is what puts the money on
            // that customer's history.
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'date' => [$required, 'date'],
            // Every figure is integer centavos. Expenses cannot be negative —
            // a refund is a smaller expense, not a negative one — but income
            // has no ceiling and no sign trick either.
            'trip_income_cents' => ['sometimes', 'integer', 'min:0'],
            'fuel_cents' => ['sometimes', 'integer', 'min:0'],
            'driver_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'helper_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'maintenance_cents' => ['sometimes', 'integer', 'min:0'],
            'allowance_cents' => ['sometimes', 'integer', 'min:0'],
            'route' => ['nullable', 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:255'],
            // Rejected outright rather than ignored, so a client that sends a
            // total finds out it is derived instead of wondering why it moved.
            'total_expenses_cents' => ['prohibited'],
            'net_income_cents' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'total_expenses_cents.prohibited' => 'Total expenses is derived from the five expense fields; do not send it.',
            'net_income_cents.prohibited' => 'Net income is derived from income minus expenses; do not send it.',
        ];
    }

    public function toData(): LedgerEntryData
    {
        return LedgerEntryData::fromArray($this->validated());
    }
}
