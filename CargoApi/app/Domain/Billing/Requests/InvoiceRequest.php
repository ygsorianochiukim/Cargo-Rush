<?php

declare(strict_types=1);

namespace App\Domain\Billing\Requests;

use App\Domain\Billing\DTO\InvoiceData;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id', 'required_if:direction,receivable'],
            'payee' => ['nullable', 'string', 'max:160', 'required_if:direction,payable'],
            'issued_at' => [$required, 'date'],
            'due_at' => [$required, 'date', 'after_or_equal:issued_at'],
            'amount_cents' => [$required, 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'direction' => [$required, Rule::in(InvoiceDirection::values())],
            'status' => [
                'sometimes',
                Rule::in([
                    StatusValue::Pending->value,
                    StatusValue::Overdue->value,
                    StatusValue::Cancelled->value,
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required_if' => 'A receivable has to name the customer being billed.',
            'payee.required_if' => 'A payable has to name who is being paid.',
            'due_at.after_or_equal' => 'An invoice cannot fall due before it was issued.',
            'status.in' => 'Paid and part-paid follow from the payments against a document. '
                .'Settle it, or record the payment, and the status follows.',
        ];
    }

    public function toData(): InvoiceData
    {
        return InvoiceData::fromArray($this->validated());
    }
}
