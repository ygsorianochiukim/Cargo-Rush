<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Requests;

use App\Domain\Inspection\DTO\InspectionData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class InspectionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'trip_id' => ['nullable', 'string', 'exists:trips,id'],
            'vehicle_id' => ['required', 'string', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            'results' => ['required', 'array', 'min:1'],
            'results.*' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'inspected_at' => ['sometimes', 'date', 'before_or_equal:now'],
            // The pass/fail call belongs to the API — see InspectionService.
            'good_to_go' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'good_to_go.prohibited' => 'Whether the unit is good to go is decided from the checklist, not sent with it.',
        ];
    }

    public function toData(): InspectionData
    {
        return InspectionData::fromArray($this->validated());
    }
}
