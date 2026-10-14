<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Requests\Concerns\GuardsTheTypedPrice;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfirmTripRequest extends ApiFormRequest
{
    use GuardsTheTypedPrice;

    public function rules(): array
    {
        return [
            'driver_id' => ['required', 'string', Rule::exists('drivers', 'id')->where('role', CrewRole::Driver->value)],
            'helper_ids' => ['sometimes', 'nullable', 'array', 'max:5'],
            'helper_ids.*' => ['string', 'distinct', 'exists:drivers,id', 'different:driver_id'],
            'vehicle_id' => ['required', 'string', 'exists:vehicles,id'],
            'scheduled_at' => ['required', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'weight_kg' => ['sometimes', 'integer', 'min:0', 'max:60000'],
            'distance_total_m' => ['sometimes', 'integer', 'min:0'],
            'price_cents' => $this->priceRules(),
            'pricing_zone_id' => [
                'nullable', 'string',
                Rule::exists('pricing_zones', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [$this->mustBeAllowedToPrice(...), function (Validator $validator): void {
            if ($this->route('trip')?->trucker_id !== null) {
                $validator->errors()->add(
                    'vehicle_id',
                    'A trucker has been asked to haul this run in their own truck. Release the trucker before putting your own crew on it.',
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'driver_id.required' => 'Name the driver who is taking this run.',
            'driver_id.exists' => 'Pick someone from the driver roster to take the wheel.',
            'vehicle_id.required' => 'Name the unit this run goes out on.',
            'scheduled_at.required' => 'Say when this run is going out.',
            'helper_ids.*.different' => 'A helper cannot be the same person as the driver.',
            'helper_ids.*.distinct' => 'The same helper is named twice.',
            'helper_ids.max' => 'A run can carry at most five helpers.',
            'eta.after_or_equal' => 'The ETA cannot be earlier than the scheduled departure.',
        ];
    }

    public function toData(): TripData
    {
        return TripData::fromArray([
            ...$this->withoutUnmovedPrice($this->validated()),
            'status' => StatusValue::Assigned->value,
        ]);
    }
}
