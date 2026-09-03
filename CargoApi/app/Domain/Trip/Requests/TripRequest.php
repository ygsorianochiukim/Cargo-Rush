<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trip\DTO\TripData;
use Illuminate\Validation\Rule;

class TripRequest extends ApiFormRequest
{
    /**
     * The statuses the office owns.
     *
     * Booking work is the office's job; reporting what happened to it on the
     * road is the driver's. So `in_transit` and `delivered` are missing here
     * deliberately — they are reached by the driver leaving on the run
     * (`POST trips/{trip}/start`) and handing it over
     * (`POST trips/current/deliver`), each of which does more than set a
     * column: they open the dispatch record, file the delivery log with its
     * proof, and credit the driver. A form that could set either would
     * produce a trip that says `delivered` with no proof behind it.
     *
     * `overdue` is absent for a different reason: it is derived from the ETA
     * against the clock by `cargo:trips-overdue`, so typing it in would only
     * be overwritten.
     */
    private const OFFICE_SETTABLE = [
        StatusValue::Scheduled->value,
        StatusValue::Assigned->value,
        StatusValue::Pending->value,
        StatusValue::Cancelled->value,
    ];

    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'origin' => [$required, 'string', 'max:160'],
            // Coordinates are optional — a trip booked over the phone has a
            // place name long before anybody has pinned it — but a lone
            // latitude is not a location, so each requires its pair.
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'destination' => [$required, 'string', 'max:160'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'cargo' => [$required, 'string', 'max:255'],
            'weight_kg' => [$required, 'integer', 'min:0', 'max:60000'],
            'pieces' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'handling' => ['nullable', 'string', 'max:255'],
            // The tariff quotes the price (`PricingService`), so this is not
            // a field the booking form normally sends. It is here for the one
            // case deriving cannot cover: a rate the office negotiated. Zero
            // is meaningful and is kept — that is how the company's own
            // freight is booked, and it is why the service asks whether the
            // key was sent rather than whether it is empty.
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            // A helper who is also the driver is a data-entry slip, not a crew.
            'helper_id' => ['nullable', 'string', 'exists:drivers,id', 'different:driver_id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],
            'status' => ['sometimes', Rule::in(self::OFFICE_SETTABLE)],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => [$required, 'date'],
            // An ETA before the unit even leaves cannot be right.
            'eta' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'distance_total_m' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'helper_id.different' => 'The helper cannot be the same person as the driver.',
            'eta.after_or_equal' => 'The ETA cannot be earlier than the scheduled departure.',
            'origin_lat.required_with' => 'A longitude needs its latitude.',
            'origin_lng.required_with' => 'A latitude needs its longitude.',
            'destination_lat.required_with' => 'A longitude needs its latitude.',
            'destination_lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    public function toData(): TripData
    {
        return TripData::fromArray($this->validated());
    }
}
