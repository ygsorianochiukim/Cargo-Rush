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
            /*
             * No truck and no vehicle, and their absence is the point.
             *
             * This form used to attach spend to a unit, which made it the place
             * an oil change was filed — so a list otherwise full of meals and
             * tarpaulins was also the fleet's service history, and neither was
             * findable. What a unit costs to run now belongs to the unit: a
             * maintenance job carries what it came to, and that figure lands in
             * that truck's Maintenance column on the daily sheet. See
             * `MaintenanceService`.
             *
             * The columns stay on the table. `TruckRentService` still writes a
             * vehicle onto the monthly rent charge it raises, and the rows filed
             * before this keep what they were filed with — this is a form that
             * stopped asking, not a fact that stopped existing.
             *
             * ## And no driver either, for the same reason
             *
             * It asked who the money was for, which sounded harmless and was
             * not: a driver on an expense is what made this screen look like
             * the place to file what a crew cost, and what a crew costs is
             * payroll and the daily sheet's own driver and helper columns.
             * Other Expenses is the supplies and the sundries — the rice, the
             * tarpaulins, the tolls, the office rent — and none of those belong
             * to a person any more than they belong to a truck.
             *
             * `driver_id` stays on the table and on the resource for the rows
             * already filed with one, and the list can still be filtered by it.
             * What is gone is the form asking, and this accepting.
             */
            'trip_id' => ['nullable', 'string', 'exists:trips,id'],
            'supplier_id' => ['nullable', 'string', 'exists:suppliers,id'],
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
