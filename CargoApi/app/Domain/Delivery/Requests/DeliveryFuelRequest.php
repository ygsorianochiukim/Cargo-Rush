<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

class DeliveryFuelRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'fuel_litres' => ['present', 'nullable', 'numeric', 'min:0', 'max:5000'],
            'fuel_cost_cents' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('delivery')?->status !== StatusValue::Delivered) {
                $validator->errors()->add('fuel_litres', 'Fuel is encoded once the trip has been delivered.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'fuel_cost_cents.integer' => 'Send the amount in centavos as a whole number, not pesos.',
        ];
    }

    /** @return array{fuel_litres: float|null, fuel_cost_cents: int|null} */
    public function fuel(): array
    {
        $litres = $this->validated('fuel_litres');
        $cost = $this->validated('fuel_cost_cents');

        return [
            'fuel_litres' => $litres === null ? null : round((float) $litres, 2),
            'fuel_cost_cents' => $cost === null ? null : (int) $cost,
        ];
    }
}
