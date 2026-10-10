<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trip\DTO\TripData;

class DeliveryRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'carrier_id' => ['nullable', 'string', 'size:26'],

            'origin' => ['required', 'string', 'max:160'],
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'destination' => ['required', 'string', 'max:160'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
            'cargo' => ['required', 'string', 'max:255'],
            'weight_kg' => ['required', 'integer', 'min:1', 'max:60000'],
            'pieces' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'handling' => ['nullable', 'string', 'max:255'],
            'preferred_at' => ['required', 'date', 'after_or_equal:now'],

            'trucker_id' => ['nullable', 'string', 'max:26'],
        ];
    }

    public function messages(): array
    {
        return [
            'weight_kg.min' => 'A delivery has to weigh something. Enter the weight in kilograms.',
            'preferred_at.after_or_equal' => 'Choose a pickup time that has not already passed.',
            'carrier_id.size' => 'That is not a carrier we know. Pick one from the list.',
            'origin_lat.required_with' => 'A longitude needs its latitude.',
            'origin_lng.required_with' => 'A latitude needs its longitude.',
            'destination_lat.required_with' => 'A longitude needs its latitude.',
            'destination_lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    public function carrierId(): ?string
    {
        $id = trim((string) $this->input('carrier_id', ''));

        return $id === '' ? null : $id;
    }

    public function truckerId(): ?string
    {
        $id = trim((string) $this->input('trucker_id', ''));

        return $id === '' ? null : $id;
    }

    public function toData(string $customerId, int $userId): TripData
    {
        $validated = $this->validated();

        unset($validated['preferred_at'], $validated['carrier_id'], $validated['trucker_id']);

        return TripData::fromArray([
            ...$validated,
            'customer_id' => $customerId,
            'requested_by' => $userId,
            'scheduled_at' => $this->date('preferred_at')?->toIso8601String(),
        ]);
    }
}
