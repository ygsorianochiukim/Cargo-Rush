<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The firm's plain distance card — the lines that belong to no zone.
 *
 * "450 km is ₱5,000", said without inventing a zone per town first. These lines
 * apply wherever nothing more specific does, and for most hauliers they are the
 * whole rate card: the zone editor is for the firms that genuinely price Davao
 * differently from Tagum.
 *
 * The whole card arrives at once, as the zone editor's does, because that is
 * how it is edited — somebody adds a line, corrects a rate on another, deletes
 * a third and presses save once.
 */
class PricingCardRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'brackets' => ['present', 'array', 'max:40'],
            'brackets.*.id' => ['nullable', 'string', 'exists:pricing_brackets,id'],
            'brackets.*.label' => ['required', 'string', 'max:60'],
            'brackets.*.min_km' => ['required', 'integer', 'min:0', 'max:100000'],
            'brackets.*.max_km' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'brackets.*.base_cents' => ['required', 'integer', 'min:0'],

            /**
             * Pesos added per ₱1/L of diesel above the baseline, in centavos.
             *
             * Here as well as on a zone's lines, because a firm may well band
             * part of its card and price the rest plainly — a subsidy table to
             * 600 km with one "600 km and beyond" line under it — and the line
             * past the end of the table needs the same fuel arithmetic as the
             * ones inside it. Zero or absent keeps the percentage model.
             */
            'brackets.*.diesel_step_cents' => ['sometimes', 'integer', 'min:0'],

            'brackets.*.per_km_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.per_kg_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.minimum_cents' => ['sometimes', 'integer', 'min:0'],

            /**
             * Which kind of unit this line prices. Null is "any".
             *
             * Scoped to the caller's company: `exists` runs outside the tenant
             * scope, so without the clause a card could be pointed at another
             * firm's category and price nothing this company can see.
             */
            'brackets.*.truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Two mistakes a card can express, and neither shows up until a quote is
     * wrong.
     *
     * A line ending before it starts prices nothing. Two lines covering the
     * same distance **for the same kind of unit** make the price depend on row
     * order — which is precisely what `BracketResolver`'s specificity rule
     * exists to avoid having to think about, and it can only do that if the
     * card has no ambiguity at a given specificity.
     *
     * Overlap is checked *within* a category rather than across the card: a
     * general 0–50 km line and a freezer 0–50 km line are the whole point, not
     * a clash.
     */
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function brackets(): array
    {
        return (array) ($this->validated()['brackets'] ?? []);
    }
}
