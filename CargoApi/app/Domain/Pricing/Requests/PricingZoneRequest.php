<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Pricing\DTO\PricingZoneData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A zone as the editor sends it: a band of kilometres, and a rate line per
 * class of truck.
 *
 * This is one row of a subsidy table read across — `A1`, 1 to 40 km, ₱4,165
 * with ₱14 a peso of diesel on top, and the same again for a brand-new unit
 * and a recon one.
 *
 * Two things a caller can no longer send. `aliases` is gone, because a zone is
 * not a place. And a line inside a zone can no longer carry its own
 * kilometres: the band belongs to the zone, and letting a line restate it is
 * how the two come to disagree about what 40 km means. The plain distance card
 * keeps its kilometres — see `PricingCardRequest`.
 */
class PricingZoneRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $zone = $this->route('zone');

        return [
            /**
             * Unique within the company, like the code below.
             *
             * Not a database constraint — there is no index on it — but a rule
             * all the same, because the band list is read by name and two rows
             * called "Zone A" are indistinguishable on the screen somebody
             * checks the card on. The code catches a retyped band; the name
             * catches the same band typed again under a different code, which
             * is the commoner mistake and the one nothing else here notices.
             */
            'name' => [
                $required, 'string', 'max:80',
                Rule::unique('pricing_zones', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($zone?->id)
                    ->whereNull('deleted_at'),
            ],
            /**
             * The cell of the table: `A1`, `E2`, `O`.
             *
             * `alpha_dash` still, so it stays an identifier, and unique **per
             * company** — two hauliers both working from a table with an `A1`
             * in it is the normal case rather than a collision.
             *
             * The company clause is the half that was missing. `Rule::unique`
             * builds its own query and never passes through the tenant scope,
             * so without it the rule was global: one firm seeding a subsidy
             * table would take `A1` away from every other firm on the install,
             * and the refusal would name a row they cannot see.
             *
             * ## A retired band keeps its code, and there is no `whereNull`
             *
             * The unique index is on `(company_id, code)` and knows nothing
             * about `deleted_at`. A rule that excused soft-deleted rows would
             * therefore pass validation and then hit the database — a 500 with
             * a constraint name in it, where the office typed a duplicate and
             * deserved to be told so.
             *
             * Matching the index is also the right answer on its own terms. A
             * band is soft-deleted rather than removed so the trips it priced
             * can still say where their figure came from, and letting a second
             * `A1` exist would make that trace ambiguous: two bands, one code,
             * and nothing on an old invoice to say which of them quoted it.
             */
            'code' => [
                $required, 'string', 'max:40', 'alpha_dash',
                Rule::unique('pricing_zones', 'code')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($zone?->id),
            ],

            /**
             * The band. Inclusive `min_km`, and `max_km` the first kilometre
             * the band no longer covers.
             *
             * A table printing "1 to 40" is sent as 1 and 41. The API does not
             * convert, on purpose: a client that wrote 40 meaning inclusive
             * and an API that read it as exclusive would be a card a
             * kilometre short in every band, and no error anywhere would say
             * so. One convention, stated in both directions.
             */
            'min_km' => [$required, 'integer', 'min:0', 'max:100000'],
            /** Null is the open-ended top band — "561 km and beyond". */
            'max_km' => ['nullable', 'integer', 'min:1', 'max:100000'],

            /**
             * The pump price this band's printed figures already cover.
             *
             * On a subsidy card it is the **top** of the baseline band — the
             * workbook's holds from ₱30 to ₱43 a litre, so ₱43.00 goes here
             * and diesel anywhere below it adds nothing.
             */
            'diesel_baseline_cents' => ['nullable', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'notes' => ['nullable', 'string', 'max:255'],

            // The lines, sent whole. Absent leaves the existing rows alone, so
            // a PATCH that only renames a band does not silently wipe its rates.
            'brackets' => ['sometimes', 'array', 'max:20'],
            'brackets.*.id' => ['nullable', 'string', 'exists:pricing_brackets,id'],
            'brackets.*.label' => ['required', 'string', 'max:60'],
            'brackets.*.base_cents' => ['required', 'integer', 'min:0'],

            /**
             * Pesos added per ₱1/L of diesel above the baseline, in centavos.
             *
             * The rightmost column of the printed table, and the figure that
             * makes a banded card reproduce it: A1's ₱14 and O's ₱210. Zero or
             * absent leaves the line on the percentage fuel model instead.
             */
            'brackets.*.diesel_step_cents' => ['sometimes', 'integer', 'min:0'],

            // Kept for a firm that bands its card and still charges by weight.
            // Zero on a subsidy line, where the band already accounts for the
            // distance and charging per kilometre would bill it twice.
            'brackets.*.per_km_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.per_kg_cents' => ['sometimes', 'integer', 'min:0'],
            'brackets.*.minimum_cents' => ['sometimes', 'integer', 'min:0'],

            /**
             * Which class of unit this line prices. Null is "the fleet" — the
             * table's own `Current Price` column.
             *
             * Scoped to the caller's company: `exists` runs outside the tenant
             * scope, so without the clause a card could name another firm's
             * category and price nothing this company can see.
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
     * The rules a zone has to satisfy as a whole, which no per-field rule can
     * express.
     *
     * A band ending before it starts covers nothing. Two lines for the same
     * class of unit make the price depend on row order — which is precisely
     * what `BracketResolver`'s specificity rule exists to avoid having to
     * think about, and it can only do that if the card has no ambiguity at a
     * given specificity.
     *
     * What is explicitly **not** checked is two zones over the same band. A1
     * beside A2 is the table, not a mistake: they are the same kilometres at
     * different money, and the desk chooses. `ZoneResolver` documents how.
     */
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
