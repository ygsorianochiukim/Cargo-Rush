<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Requests;

use App\Domain\Fuel\DTO\FuelRecordData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class FuelRecordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'vehicle_id' => [$required, 'string', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            'litres' => [$required, 'numeric', 'min:0.01', 'max:2000'],
            // Integer centavos. A float peso amount is rejected here, not
            // silently rounded somewhere downstream.
            'amount_cents' => [$required, 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'odometer_km' => [$required, 'integer', 'min:0'],
            'receipt_no' => [$required, 'string', 'max:40'],
            'logged_at' => [$required, 'date'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.integer' => 'Send the amount in centavos as a whole number, not pesos.',
        ];
    }

    public function toData(): FuelRecordData
    {
        return FuelRecordData::fromArray($this->validated());
    }
}
