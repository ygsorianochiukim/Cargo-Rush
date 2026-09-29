<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Finance\DTO\ExpenseCategoryData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $category = $this->route('category');

        return [
            'name' => [$required, 'string', 'max:60'],
            // Optional: the service slugs the name on create. Sent explicitly
            // it has to be unique, because it is what seeded rows are found by.
            'key' => [
                'sometimes', 'string', 'max:40', 'alpha_dash',
                Rule::unique('expense_categories', 'key')->ignore($category?->id)->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'icon' => ['nullable', 'string', 'max:40'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            // An account in this company's chart, or nothing for the default.
            'account_code' => [
                'nullable', 'string', 'max:20',
                Rule::exists('accounts', 'code')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function toData(): ExpenseCategoryData
    {
        return ExpenseCategoryData::fromArray($this->validated());
    }
}
