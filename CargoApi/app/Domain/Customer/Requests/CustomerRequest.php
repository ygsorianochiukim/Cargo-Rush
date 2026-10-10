<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Customer\DTO\CustomerData;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;

class CustomerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:160'],
            'contact' => [$required, 'string', 'max:160'],

            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],

            'tin' => ['nullable', 'string', 'max:20'],
            'vat_treatment' => ['sometimes', Rule::in(VatTreatment::values())],
            'withholds_tax' => ['sometimes', 'boolean'],
            'withholding_rate_bp' => ['nullable', 'integer', 'min:0', 'max:5000'],

            'email' => ['nullable', 'email', 'max:160', $this->unusedAddress()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'That address already has an account. Use another, or leave it blank.',
        ];
    }

    public function toData(): CustomerData
    {
        return CustomerData::fromArray($this->validated());
    }

    private function unusedAddress(): object
    {
        $customer = $this->route('customer');

        $rule = Rule::unique('users', 'email');

        if ($customer instanceof Customer) {
            $rule->where(fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->whereNull('customer_id')
                ->orWhere('customer_id', '!=', $customer->id)));
        }

        return $rule;
    }
}
