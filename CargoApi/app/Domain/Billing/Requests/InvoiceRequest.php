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
            // `number` is deliberately absent: it is the model's to assign, so
            // a value sent here is stripped rather than honoured. Letting a
            // client choose it meant two people filling the form at once could
            // pick the same number, and the unique index would reject the
            // second as a validation error on a field nobody should be typing.
            // A receivable needs a customer; a payable needs a payee. Neither
            // makes sense as an unaddressed document.
            'customer_id' => ['nullable', 'string', 'exists:customers,id', 'required_if:direction,receivable'],
            'payee' => ['nullable', 'string', 'max:160', 'required_if:direction,payable'],
            'issued_at' => [$required, 'date'],
            'due_at' => [$required, 'date', 'after_or_equal:issued_at'],
            'amount_cents' => [$required, 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'direction' => [$required, Rule::in(InvoiceDirection::values())],
            'status' => ['sometimes', Rule::in(StatusValue::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required_if' => 'A receivable has to name the customer being billed.',
            'payee.required_if' => 'A payable has to name who is being paid.',
            'due_at.after_or_equal' => 'An invoice cannot fall due before it was issued.',
        ];
    }

    public function toData(): InvoiceData
    {
        return InvoiceData::fromArray($this->validated());
    }
}
