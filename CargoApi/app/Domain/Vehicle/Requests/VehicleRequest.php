<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Vehicle\DTO\VehicleData;
use Illuminate\Validation\Rule;

class VehicleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'plate' => [
                $required, 'string', 'max:20',
                Rule::unique('vehicles', 'plate')->ignore($this->route('vehicle')),
            ],
            'model' => [$required, 'string', 'max:80'],
            'registration_no' => [$required, 'string', 'max:60'],
            'capacity_kg' => [$required, 'integer', 'min:0', 'max:60000'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            'odometer_km' => ['sometimes', 'integer', 'min:0'],
            // Servicing a unit at a reading it has already passed is not a plan.
            'next_service_km' => ['sometimes', 'integer', 'min:0', 'gte:odometer_km'],
        ];
    }

    public function messages(): array
    {
        return [
            'next_service_km.gte' => 'The next service reading must be at or beyond the current odometer.',
        ];
    }

    public function toData(): VehicleData
    {
        return VehicleData::fromArray($this->validated());
    }
}
