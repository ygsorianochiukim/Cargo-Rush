<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Pricing\DTO\PricingZoneData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PricingZoneRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $zone = $this->route('zone');

        return [
            'name' => [$required, 'string', 'max:80'],
            'code' => [
                $required, 'string', 'max:40', 'alpha_dash',
                Rule::unique('pricing_zones', 'code')->ignore($zone?->id)->whereNull('deleted_at'),
            ],
            'aliases' => ['sometimes', 'array', 'max:40'],
            'aliases.*' => ['string', 'max:80'],
            'diesel_baseline_cents' => ['nullable', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'notes' => ['nullable', 'string', 'max:255'],

            // The card, sent whole. Absent leaves the existing rows alone, so a
            // PATCH that only renames a zone does not silently wipe its rates.
            'brackets' => ['sometimes', 'array', 'max:20'],
            'brackets.*.id' => ['nullable', 'string', 'exists:pricing_brackets,id'],
            'brackets.*.label' => ['required', 'string', 'max:60'],
            'brackets.*.min_km' => ['required', 'integer', 'min:0', 'max:100000'],
            // Null is the open-ended top bracket — "80 km and beyond".
            'brackets.*.max_km' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'brackets.*.base_cents' => ['required', 'integer', 'min:0'],
            'brackets.*.per_km_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.per_kg_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.minimum_cents' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * The rules a card has to satisfy as a whole, which no per-field rule can
     * express: a bracket ending before it starts, and two brackets claiming
     * the same distance.
     *
     * Overlap matters more than it looks. Two brackets covering 30 km means the
     * quote depends on row order, so the same run priced twice can come back
     * at two figures and nothing in the data says which was right.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $brackets = (array) $this->input('brackets', []);

            if ($brackets === []) {
                return;
            }

            foreach ($brackets as $i => $bracket) {
                $min = (int) ($bracket['min_km'] ?? 0);
                $max = $bracket['max_km'] ?? null;

                if ($max !== null && (int) $max <= $min) {
                    $validator->errors()->add(
                        "brackets.$i.max_km",
                        'A bracket has to end further out than it starts.',
                    );
                }
            }

            foreach ($this->overlaps($brackets) as [$i, $label]) {
                $validator->errors()->add(
                    "brackets.$i.min_km",
                    "This overlaps \"$label\". Two brackets covering the same distance would price the same run two ways.",
                );
            }
        });
    }

    /**
     * Pairs of brackets that both claim some distance.
     *
     * Ranges are half-open — `min` inclusive, `max` exclusive — so 0–20 and
     * 20–50 sit flush and do not count as an overlap.
     *
     * @param  array<int, array<string, mixed>>  $brackets
     * @return array<int, array{0: int, 1: string}>
     */
    private function overlaps(array $brackets): array
    {
        $found = [];
        $normalised = [];

        foreach ($brackets as $i => $bracket) {
            $normalised[$i] = [
                'min' => (int) ($bracket['min_km'] ?? 0),
                'max' => isset($bracket['max_km']) && $bracket['max_km'] !== null
                    ? (int) $bracket['max_km']
                    : PHP_INT_MAX,
                'label' => (string) ($bracket['label'] ?? 'another bracket'),
            ];
        }

        foreach ($normalised as $i => $a) {
            foreach ($normalised as $j => $b) {
                if ($j >= $i) {
                    continue;
                }

                if ($a['min'] < $b['max'] && $b['min'] < $a['max']) {
                    $found[] = [$i, $b['label']];
                    break;
                }
            }
        }

        return $found;
    }

    public function messages(): array
    {
        return [
            'code.alpha_dash' => 'The code is an id, so letters, numbers and dashes only — like "davao-city".',
            'brackets.*.base_cents.integer' => 'Send bracket rates in centavos as whole numbers, not pesos.',
        ];
    }

    public function toData(): PricingZoneData
    {
        return PricingZoneData::fromArray($this->validated());
    }
}
