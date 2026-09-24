<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
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

            /**
             * On what terms the truck runs for the fleet.
             *
             * Absent means `owned`, which is the column default and the right
             * reading of a form that never asked: a fleet adding a unit
             * without saying otherwise has bought one.
             */
            'arrangement' => ['sometimes', Rule::in(VehicleArrangement::values())],

            /**
             * How many wheels — what the desk means by "a ten-wheeler".
             *
             * Informational: nothing computes money from it, because the rate
             * is negotiated per truck and lives on `share_bp`. Bounded at four
             * and twenty-two so an obvious slip is caught.
             */
            'wheels' => ['nullable', 'integer', 'min:4', 'max:22'],

            'owner_name' => ['nullable', 'string', 'max:120'],
            'owner_contact' => ['nullable', 'string', 'max:40'],

            /** The monthly fee, in centavos. `rented` only. */
            'rent_cents' => ['nullable', 'integer', 'min:0'],

            /**
             * The fleet's cut of a run, in basis points.
             *
             * Bounded the same way a partner trucker's rate is, and for the
             * same reasons: nought is a real arrangement, and anything over
             * half is a typo rather than a negotiation.
             */
            'share_bp' => ['nullable', 'integer', 'min:0', 'max:5000'],

            /** Who the share is owed to — a partner record. */
            'owner_trucker_id' => ['nullable', 'string', 'exists:truckers,id'],
        ];
    }

    /**
     * The terms have to add up to an arrangement that can actually pay
     * somebody.
     *
     * Checked here rather than left to the money code, because the failure
     * would otherwise be silent and late: a truck marked `rented_share` with
     * nobody to pay would haul for a month and credit nothing, and the first
     * anybody heard of it would be the owner asking where their money was.
     */
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
