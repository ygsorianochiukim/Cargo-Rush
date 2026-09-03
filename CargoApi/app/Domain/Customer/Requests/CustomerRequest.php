<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Customer\DTO\CustomerData;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\StatusValue;
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
            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],

            /**
             * What the firm signs in with. Optional, because a customer the
             * office only ever books for on the phone does not need an
             * account — but given one, `CustomerService` creates the login
             * along with the record, so the firm can book its own work
             * straight away.
             *
             * Unique against `users`, since that is where it lands: an address
             * already in use is somebody else's account, and quietly attaching
             * a second firm to it would let one customer read another's
             * deliveries.
             */
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

    /**
     * Unique among accounts, except the ones this customer already holds.
     *
     * Written the long way round rather than as `whereNot('customer_id', ...)`
     * because staff accounts have no customer at all, and a plain `<>` in SQL
     * drops those rows — which would let a customer login be created on an
     * address the office already signs in with.
     */
    private function unusedAddress(): object
    {
        $customer = $this->route('customer');

        $rule = Rule::unique('users', 'email');

        if ($customer instanceof Customer) {
            // Nested, because the closure's conditions are ANDed onto the address
            // check at the top level — and `A and B or C` in SQL is not the
            // question being asked.
            $rule->where(fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->whereNull('customer_id')
                ->orWhere('customer_id', '!=', $customer->id)));
        }

        return $rule;
    }
}
