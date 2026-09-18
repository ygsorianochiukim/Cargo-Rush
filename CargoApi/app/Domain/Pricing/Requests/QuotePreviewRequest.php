<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

/**
 * "What would this run cost?" — asked before a trip exists.
 *
 * Every field is optional because the point of the preview is to answer with
 * whatever the desk has so far. A caller who names only a distance gets the
 * band it falls in and the fleet rate for it, which is the thing somebody
 * checking a card against the printed table actually needs.
 *
 * `destination` and `origin` are gone. They were what chose the card, matched
 * as substrings against per-town aliases, and a zone is now a band of
 * kilometres — a destination decides nothing, and a preview that still
 * accepted one would look like it did.
 */
class QuotePreviewRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'distance_m' => ['sometimes', 'integer', 'min:0'],
            // Kilometres too, and the more useful of the two here: a card is
            // written in kilometres, so somebody checking a band should not
            // have to convert to metres to be rounded straight back up.
            'distance_km' => ['sometimes', 'numeric', 'min:0'],
            'weight_kg' => ['sometimes', 'integer', 'min:0'],

            /**
             * The class of unit the run needs.
             *
             * Optional, and absent means the desk has not asked for anything
             * particular — which takes the band's fleet line rather than being
             * a refusal. A preview is for whatever the desk has so far.
             */
            'truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],

            /**
             * The band, where the desk has chosen one.
             *
             * This is what makes A2 quotable. Two zones cover 1–40 km at
             * different money, the distance cannot decide between them, and
             * without a way to name one the preview could only ever show A1 —
             * which would make half of every subsidy table unreachable from
             * the screen the office checks it on.
             */
            'pricing_zone_id' => [
                'nullable', 'string',
                Rule::exists('pricing_zones', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function distanceMetres(): int
    {
        if ($this->filled('distance_km')) {
            return (int) round((float) $this->input('distance_km') * 1000);
        }

        return (int) $this->integer('distance_m');
    }
}
