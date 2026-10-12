<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
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
            'next_service_km' => ['sometimes', 'integer', 'min:0', 'gte:odometer_km'],

            'arrangement' => ['sometimes', Rule::in(VehicleArrangement::values())],

            'wheels' => ['nullable', 'integer', 'min:4', 'max:22'],

            'truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],

            'owner_name' => ['nullable', 'string', 'max:120'],
            'owner_contact' => ['nullable', 'string', 'max:40'],

            'rent_cents' => ['nullable', 'integer', 'min:0'],

            'share_bp' => ['nullable', 'integer', 'min:0', 'max:5000'],

            'owner_trucker_id' => ['nullable', 'string', 'exists:truckers,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $arrangement = $this->input('arrangement');

            if ($arrangement === null) {
                return;
            }

            $terms = VehicleArrangement::from((string) $arrangement);

            if ($terms->sharesRevenue() && ! $this->filled('owner_trucker_id')) {
                $validator->errors()->add(
                    'owner_trucker_id',
                    'Say who gets the share. Add them as a trucker first if they are not on the roster.',
                );
            }

            if ($terms->chargesRent() && ! $this->filled('rent_cents')) {
                $validator->errors()->add('rent_cents', 'Enter the monthly rent for this truck.');
            }
        });
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
