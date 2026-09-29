<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

/** A supplier category's name and whether it is still offered. */
class SupplierCategoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                $this->requiredOnCreate(), 'string', 'max:60',
                Rule::unique('supplier_categories', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at')
                    ->ignore($this->route('supplierCategory')),
            ],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'There is already a category by that name.'];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $attributes = $this->validated();

        if (isset($attributes['name'])) {
            $attributes['name'] = trim((string) $attributes['name']);
        }

        return $attributes;
    }
}
