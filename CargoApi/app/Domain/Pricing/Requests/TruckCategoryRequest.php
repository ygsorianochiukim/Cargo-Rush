<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class TruckCategoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $category = $this->route('truckCategory');

        return [
            'name' => [
                $required, 'string', 'max:60',
                Rule::unique('truck_categories', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($category?->id)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'cbm' => ['nullable', 'integer', 'min:1', 'max:200'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a truck category with that name.',
        ];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return $this->safe()->only(['name', 'description', 'cbm', 'position', 'status']);
    }
}
