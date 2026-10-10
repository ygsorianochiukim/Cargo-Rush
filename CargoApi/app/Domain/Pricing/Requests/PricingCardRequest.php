<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PricingCardRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'brackets' => ['present', 'array', 'max:40'],
            'brackets.*.id' => ['required', 'string', 'exists:pricing_brackets,id'],
            'brackets.*.label' => ['required', 'string', 'max:60'],
            'brackets.*.min_km' => ['required', 'integer', 'min:0', 'max:100000'],
            'brackets.*.max_km' => ['nullable', 'integer', 'min:1', 'max:100000'],
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
            $brackets = (array) $this->input('brackets', []);
            $seen = [];

            foreach ($brackets as $i => $bracket) {
                $min = (int) ($bracket['min_km'] ?? 0);
                $max = isset($bracket['max_km']) && $bracket['max_km'] !== null
                    ? (int) $bracket['max_km']
                    : null;

                if ($max !== null && $max <= $min) {
                    $validator->errors()->add(
                        "brackets.{$i}.max_km",
                        'A line has to end further out than it starts.',
                    );

                    continue;
                }

                $category = $bracket['truck_category_id'] ?? '*';

                foreach ($seen[$category] ?? [] as [$otherMin, $otherMax]) {
                    $overlaps = ($max === null || $otherMin < $max)
                        && ($otherMax === null || $min < $otherMax);

                    if ($overlaps) {
                        $validator->errors()->add(
                            "brackets.{$i}.min_km",
                            'Two lines cover this distance for the same kind of truck, so a quote '
                            .'would depend on which came first. Adjust one of them.',
                        );

                        break;
                    }
                }

                $seen[$category][] = [$min, $max];
            }
        });
    }

    public function messages(): array
    {
        return [
            'brackets.*.id.required' => 'The distance card no longer prices trips. Add this line to a zone on the Pricing card instead.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function brackets(): array
    {
        return (array) ($this->validated()['brackets'] ?? []);
    }
}
