<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * What the driver sends when they leave on a run.
 *
 * `location` is where they are departing from, for the dispatch record. It is
 * optional because the handset may have no fix yet and the booking already
 * knows the pickup place — a dispatch record reading "Manila depot" is worth
 * more than one that refused to open for want of a GPS lock.
 *
 * No status field, for the same reason the hand-off has none: pending → in
 * transit is the only move this performs.
 */
class StartTripRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
