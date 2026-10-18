<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Finance\DTO\ExpenseData;
use App\Domain\Shared\Enums\PaySchedule;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class ExpenseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'category_id' => [$required, 'string', 'exists:expense_categories,id'],
            'trip_id' => ['nullable', 'string', 'exists:trips,id'],
            'supplier_id' => ['nullable', 'string', 'exists:suppliers,id'],
            'payment_source_id' => [
                'nullable', 'string',
                Rule::exists('payment_sources', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'pay_schedule' => ['sometimes', Rule::in(PaySchedule::values())],
            'date' => [$required, 'date'],
            'amount_cents' => [$required, 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_vatable' => ['sometimes', 'boolean'],
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
            'pay_schedule.in' => 'An expense is paid monthly, every cutoff, or in cash on receipt.',
        ];
    }

    public function toData(): ExpenseData
    {
        return ExpenseData::fromArray($this->validated());
    }
}
