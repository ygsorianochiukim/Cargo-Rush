<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Accounting\DTO\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;

class AccountRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $account = $this->route('account');
        $id = $account instanceof Account ? $account->getKey() : null;

        return [
            'code' => [
                $required, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('accounts', 'code')
                    ->where(fn (Builder $query) => $query->where('company_id', app(Tenant::class)->id()))
                    ->whereNull('deleted_at')
                    ->ignore($id),
            ],

            'name' => [$required, 'string', 'max:120'],

            'type' => [$required, Rule::in(AccountType::values())],

            'group' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'That account number is already in your chart.',
            'code.regex' => 'An account number can hold letters, digits, dots, dashes and underscores.',
            'type.required' => 'Choose what kind of account this is — it decides which way its balance runs.',
        ];
    }

    public function toData(): AccountData
    {
        return AccountData::fromArray($this->validated());
    }
}
