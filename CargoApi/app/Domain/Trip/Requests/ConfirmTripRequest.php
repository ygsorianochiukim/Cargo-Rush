<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trip\DTO\TripData;

/**
 * The desk confirming a delivery request.
 *
 * A customer's request arrives with the load and the two ends and nothing
 * else. This is the form that supplies the four things only the office knows —
 * who drives it, who rides along, which unit, and when — and the trip becomes
 * `assigned` as a consequence of them being named.
 *
 * The driver, the vehicle and the schedule are **required**, and that is the
 * whole contract: `assigned` means a driver can act on it, so confirming
 * without a unit or a time would produce a run that says "go" and cannot be
 * gone on. The helper stays optional, because plenty of runs are one person.
 *
 * `status` is deliberately not a field. It is the outcome of this call, not an
 * input to it — the same reasoning that keeps `in_transit` and `delivered` off
 * the booking form.
 */
class ConfirmTripRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'driver_id' => ['required', 'string', 'exists:drivers,id'],
            // A helper who is also the driver is a data-entry slip, not a crew.
            'helper_id' => ['nullable', 'string', 'exists:drivers,id', 'different:driver_id'],
            'vehicle_id' => ['required', 'string', 'exists:vehicles,id'],
            'scheduled_at' => ['required', 'date'],
            // An ETA before the unit even leaves cannot be right.
            'eta' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            // Room to correct what the customer estimated, since the price is
            // re-quoted from these on the way through.
            'weight_kg' => ['sometimes', 'integer', 'min:0', 'max:60000'],
            'distance_total_m' => ['sometimes', 'integer', 'min:0'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'driver_id.required' => 'Name the driver who is taking this run.',
            'vehicle_id.required' => 'Name the unit this run goes out on.',
            'scheduled_at.required' => 'Say when this run is going out.',
            'helper_id.different' => 'The helper cannot be the same person as the driver.',
            'eta.after_or_equal' => 'The ETA cannot be earlier than the scheduled departure.',
        ];
    }

    /**
     * The confirmation, with the status it implies.
     *
     * Added here rather than in the service so `persistable()` carries it: the
     * DTO writes only the keys it was given, and the status has to be one of
     * them for the update to move the trip at all.
     */
    public function toData(): TripData
    {
        return TripData::fromArray([
            ...$this->validated(),
            'status' => StatusValue::Assigned->value,
        ]);
    }
}
