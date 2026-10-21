<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class TruckSizeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $size = $this->route('truckSize');

        return [
            'name' => [
                $required, 'string', 'max:60',
                Rule::unique('truck_sizes', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($size?->id)
                    ->whereNull('deleted_at'),
            ],
            'cbm' => [$required, 'integer', 'min:1', 'max:200'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a truck size with that name.',
        ];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return $this->safe()->only(['name', 'cbm', 'status']);
    }
}
