<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Finance\DTO\TruckData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class TruckRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'label' => [
                $this->requiredOnCreate(), 'string', 'max:60',
                Rule::unique('trucks', 'label')->ignore($this->route('truck')),
            ],
            // Null is allowed and meaningful: the unit renders as "Unassigned".
            'plate' => ['nullable', 'string', 'max:20'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function toData(): TruckData
    {
        return TruckData::fromArray($this->validated());
    }
}
