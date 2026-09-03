<?php

declare(strict_types=1);

namespace App\Domain\Incident\Requests;

use App\Domain\Incident\DTO\IncidentData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class IncidentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'kind' => [$required, 'string', 'max:120'],
            'place' => [$required, 'string', 'max:160'],
            // Something that has not happened yet is not an incident.
            'occurred_at' => [$required, 'date', 'before_or_equal:now'],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],
            'trip_id' => ['nullable', 'string', 'exists:trips,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'occurred_at.before_or_equal' => 'An incident cannot be reported for a future time.',
        ];
    }

    public function toData(): IncidentData
    {
        return IncidentData::fromArray($this->validated());
    }
}
