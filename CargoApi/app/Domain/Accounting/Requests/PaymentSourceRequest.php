<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Accounting\DTO\PaymentSourceData;
use App\Domain\Shared\Enums\PaymentSourceKind;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class PaymentSourceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [
                $required, 'string', 'max:80',
                Rule::unique('payment_sources', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at')
                    ->ignore($this->route('paymentSource')),
            ],
            'kind' => [$required, Rule::in(PaymentSourceKind::values())],
            'provider' => ['nullable', 'string', 'max:60'],
            'last_four' => ['nullable', 'digits:4'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => [
                'sometimes',
                Rule::in([StatusValue::Active->value, StatusValue::Inactive->value]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a wallet or card by that name.',
            'kind.in' => 'Pick cash, credit card, fuel card, bank account or e-wallet.',
            'last_four.digits' => 'Only the last four digits of the card.',
        ];
    }

    public function toData(): PaymentSourceData
    {
        return PaymentSourceData::fromArray($this->validated());
    }
}
