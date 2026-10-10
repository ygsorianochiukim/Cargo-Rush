<?php

declare(strict_types=1);

namespace App\Domain\Incident\Requests;

use App\Domain\Incident\DTO\IncidentData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class ReportIncidentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'max:120'],

            'place' => ['required', 'string', 'max:160'],

            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.required' => 'Say what happened — a few words is enough.',
            'place.required' => 'Say where it happened.',
            'occurred_at.before_or_equal' => 'An incident cannot be reported for a future time.',
        ];
    }

    public function toData(string $driverId, ?string $vehicleId, ?string $tripId): IncidentData
    {
        $validated = $this->validated();

        return IncidentData::fromArray([
            ...$validated,
            'occurred_at' => $this->date('occurred_at')?->toIso8601String() ?? now()->toIso8601String(),
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'trip_id' => $tripId,
        ]);
    }
}
