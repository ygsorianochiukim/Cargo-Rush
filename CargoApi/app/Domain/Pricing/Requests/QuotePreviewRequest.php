<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * "What would this run cost?" — asked before a trip exists.
 *
 * Every field is optional because the point of the preview is to answer with
 * whatever the desk has so far. A caller who names only a destination gets the
 * card for it at zero distance, which is what tells them the zone matched at
 * all — the thing somebody editing aliases actually needs to know.
 */
class QuotePreviewRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'destination' => ['nullable', 'string', 'max:160'],
            'origin' => ['nullable', 'string', 'max:160'],
            'distance_m' => ['sometimes', 'integer', 'min:0'],
            // Kilometres too, for a caller holding the distance in the unit the
            // card is written in, who should not have to convert it to be
            // rounded straight back up.
            'distance_km' => ['sometimes', 'numeric', 'min:0'],
            'weight_kg' => ['sometimes', 'integer', 'min:0'],
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
