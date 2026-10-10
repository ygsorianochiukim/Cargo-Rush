<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class MaintenanceJobRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        $onAUnit = $this->route('vehicle') !== null;

        return [
            'vehicle_id' => [$onAUnit ? 'sometimes' : $required, 'string', 'exists:vehicles,id'],
            'kind' => [$required, 'string', 'max:80'],
            'due_at' => [$required, 'date'],
            'next_service_km' => ['sometimes', 'integer', 'min:0'],
            'status' => [
                'sometimes',
                Rule::in([
                    StatusValue::Scheduled->value,
                    StatusValue::Active->value,
                    StatusValue::Delivered->value,
                    StatusValue::Cancelled->value,
                ]),
            ],

            'cost_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],

            'completed_on' => ['sometimes', 'nullable', 'date'],

            'supplier_id' => ['sometimes', 'nullable', 'string', 'exists:suppliers,id'],

            'invoice_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('invoices', 'id')->where('direction', 'payable'),
            ],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Say which truck this was done on.',
            'cost_cents.integer' => 'Send the cost in centavos as a whole number, not pesos.',
            'cost_cents.max' => 'That is over ₱100,000 for one job — check the figure.',
        ];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return $this->safe()->only([
            'kind', 'due_at', 'next_service_km', 'status',
            'cost_cents', 'completed_on', 'supplier_id', 'invoice_id', 'reference', 'note',
        ]);
    }
}
