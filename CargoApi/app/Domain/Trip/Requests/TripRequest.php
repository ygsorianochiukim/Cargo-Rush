<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Requests\Concerns\GuardsTheTypedPrice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TripRequest extends ApiFormRequest
{
    use GuardsTheTypedPrice;

    private const OFFICE_SETTABLE = [
        StatusValue::Scheduled->value,
        StatusValue::Assigned->value,
        StatusValue::Pending->value,
        StatusValue::Cancelled->value,
        StatusValue::Delivered->value,
    ];

    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'origin' => [$required, 'string', 'max:160'],
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'destination' => [$required, 'string', 'max:160'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'cargo' => [$required, 'string', 'max:255'],
            'weight_kg' => [$required, 'integer', 'min:0', 'max:60000'],
            'pieces' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'handling' => ['nullable', 'string', 'max:255'],
            'price_cents' => $this->priceRules(),
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            'helper_ids' => ['sometimes', 'nullable', 'array', 'max:5'],
            'helper_ids.*' => ['string', 'distinct', 'exists:drivers,id', 'different:driver_id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],

            'truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'pricing_zone_id' => [
                'nullable', 'string',
                Rule::exists('pricing_zones', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'status' => ['sometimes', Rule::in(self::OFFICE_SETTABLE)],
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => [$required, 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'distance_total_m' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [$this->mustBeAllowedToPrice(...), $this->oneHaulierOnly(...), function (Validator $validator): void {
            if ($this->input('status') !== StatusValue::Delivered->value) {
                return;
            }

            $scheduled = $this->input('scheduled_at') ?? $this->route('trip')?->scheduled_at;

            if ($scheduled === null) {
                return;
            }

            $scheduled = Carbon::parse($scheduled);

            if ($scheduled->isFuture()) {
                $validator->errors()->add('status', 'Only a trip that already happened can be entered as delivered.');

                return;
            }

            if ($this->filled('delivered_at') && Carbon::parse($this->input('delivered_at'))->lt($scheduled)) {
                $validator->errors()->add('delivered_at', 'It cannot have been delivered before it was scheduled.');
            }
        }];
    }

    private function oneHaulierOnly(Validator $validator): void
    {
        $trip = $this->route('trip');

        if (! $this->filled('vehicle_id') || $this->input('vehicle_id') === $trip?->vehicle_id) {
            return;
        }

        if ($this->filled('trucker_id') || $trip?->trucker_id !== null) {
            $validator->errors()->add(
                'vehicle_id',
                'A trucker is hauling this run in their own truck, so it cannot go out on a fleet unit as well. Release the trucker first.',
            );
        }
    }

    public function messages(): array
    {
        return [
            'helper_ids.*.different' => 'A helper cannot be the same person as the driver.',
            'helper_ids.*.distinct' => 'The same helper is named twice.',
            'helper_ids.max' => 'A run can carry at most five helpers.',
            'eta.after_or_equal' => 'The ETA cannot be earlier than the scheduled departure.',
            'origin_lat.required_with' => 'A longitude needs its latitude.',
            'origin_lng.required_with' => 'A latitude needs its longitude.',
            'destination_lat.required_with' => 'A longitude needs its latitude.',
            'destination_lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    public function toData(): TripData
    {
        return TripData::fromArray($this->payload());
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->withoutUnmovedPrice($this->validated());
    }
}
