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
            // Optional on purpose. A day is per truck, not per customer: it
            // can be the company's own freight, or several customers' work in
            // one row. Naming one where it applies is what puts the money on
            // that customer's history.
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],

            /**
             * Who was in the cab, so the two salary columns below have somebody
             * to belong to.
             *
             * Filled in automatically when a delivered trip opens the row, and
             * editable here because plenty of days are recorded by hand and
             * because the office is the one who can correct a day the unit
             * changed crew.
             *
             * Nullable and it stays that way: a row nobody attributed is
             * counted toward nobody's pay, which is the safe direction. Payroll
             * reads these for anybody paid per trip — see `TripPayService`.
             */
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],

            /**
             * The day's helpers, each with their own pay.
             *
             * Replaces the whole list when sent. A line may leave `driver_id`
             * empty — pay somebody entered without saying whose, counted toward
             * nobody's payslip — but may not name the driver, or the same
             * helper twice: either would pay one person twice for one day.
             */
            'helpers' => ['sometimes', 'array', 'max:5'],
            'helpers.*.driver_id' => ['nullable', 'string', 'exists:drivers,id', 'different:driver_id'],
            'helpers.*.salary_cents' => ['required', 'integer', 'min:0'],

            'date' => [$required, 'date'],
            // Every figure is integer centavos. Expenses cannot be negative —
            // a refund is a smaller expense, not a negative one — but income
            // has no ceiling and no sign trick either.
            'trip_income_cents' => ['sometimes', 'integer', 'min:0'],
            'fuel_cents' => ['sometimes', 'integer', 'min:0'],
            'driver_salary_cents' => ['sometimes', 'integer', 'min:0'],
            // One figure for every helper, from an app that predates the
            // lines above. Accepted for a day with one helper or none; see
            // `FinanceService::writeHelpers`.
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
            'helpers.*.driver_id.different' => 'The driver and a helper cannot be the same person.',
            'helpers.max' => 'A day can carry at most five helpers.',
            'total_expenses_cents.prohibited' => 'Total expenses is derived from the five expense fields; do not send it.',
            'net_income_cents.prohibited' => 'Net income is derived from income minus expenses; do not send it.',
        ];
    }

    /** Each helper once. Checked here because `distinct` would also refuse two unnamed lines. */
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
