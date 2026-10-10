<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Pricing\DTO\PricingZoneData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PricingZoneRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $zone = $this->route('zone');

        return [
            'name' => [
                $required, 'string', 'max:80',
                Rule::unique('pricing_zones', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($zone?->id)
                    ->whereNull('deleted_at'),
            ],
            'code' => [
                $required, 'string', 'max:40', 'alpha_dash',
                Rule::unique('pricing_zones', 'code')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($zone?->id),
            ],

            'min_km' => [$required, 'integer', 'min:0', 'max:100000'],
            'max_km' => ['nullable', 'integer', 'min:1', 'max:100000'],

            'diesel_baseline_cents' => ['nullable', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'notes' => ['nullable', 'string', 'max:255'],

            'brackets' => ['sometimes', 'array', 'max:20'],
            'brackets.*.id' => ['nullable', 'string', 'exists:pricing_brackets,id'],
            'brackets.*.label' => ['required', 'string', 'max:60'],
            'brackets.*.base_cents' => ['required', 'integer', 'min:0'],

            'brackets.*.diesel_step_cents' => ['sometimes', 'integer', 'min:0'],

            'brackets.*.per_km_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.per_kg_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.minimum_cents' => ['sometimes', 'integer', 'min:0'],

            'brackets.*.truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = (int) $this->input('min_km', 0);
            $max = $this->input('max_km');

            if ($max !== null && (int) $max <= $min) {
                $validator->errors()->add(
                    'max_km',
                    'A band has to end further out than it starts. Send the first kilometre it no '
                    .'longer covers — a table row reading "1 to 40" is 1 and 41.',
                );
            }

            $seen = [];

            foreach ((array) $this->input('brackets', []) as $i => $bracket) {
                $category = $bracket['truck_category_id'] ?? '*';

                if (isset($seen[$category])) {
                    $validator->errors()->add(
                        "brackets.{$i}.base_cents",
                        'This band already has a rate for that class of truck, so a quote would '
                        .'depend on which line came first. Correct the one above instead.',
                    );

                    continue;
                }

                $seen[$category] = true;
            }
        });
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This code belongs to another band — including a retired one, which keeps '
                .'its code so the trips it priced can still name it. Edit that band, or pick another code.',
            'name.unique' => 'There is already a band with this name. Edit that one rather than adding a second.',
            'code.alpha_dash' => 'The code is an id, so letters, numbers and dashes only — like "A1" or "E2".',
            'brackets.*.base_cents.integer' => 'Send rates in centavos as whole numbers, not pesos.',
            'brackets.*.diesel_step_cents.integer' => 'Send the diesel step in centavos per ₱1/L — ₱14 is 1400.',
        ];
    }

    public function toData(): PricingZoneData
    {
        return PricingZoneData::fromArray($this->validated());
    }
}
