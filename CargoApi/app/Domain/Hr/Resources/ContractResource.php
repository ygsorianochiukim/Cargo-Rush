<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\Contract;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One agreement: a figure, a basis, and the day it starts.
 *
 * @mixin Contract
 */
class ContractResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,

            'pay_basis' => $this->pay_basis?->value,
            'pay_basis_label' => $this->pay_basis?->label(),
            'pay_basis_unit' => $this->pay_basis?->unit(),
            'amount_cents' => (int) $this->amount_cents,

            /**
             * Which column of the rate card this figure came from.
             *
             * Copied onto the row when it was written, not looked up now — so a
             * contract from two years ago still says what it was, after the
             * person has been regularised and the card reprinted twice.
             */
            'tier' => $this->tier?->value,
            'tier_label' => $this->tier?->label(),

            'effective_from' => $this->effective_from?->toDateString(),
            'reason' => $this->reason,

            /** `₱15,000 a month` — the figure and what it buys, in one phrase. */
            'summary' => $this->summary(),

            /**
             * Has this one started paying?
             *
             * A row dated forward is real, on the list, and not paying yet — a
             * rise agreed today for the first of next month. Which one is
             * actually in force is in the collection's `meta`, because it is a
             * fact about the list rather than about any row: the rule is not
             * "the newest", it is "the latest that has started".
             */
            'has_started' => $this->effective_from?->isPast() ?? false,

            'author' => $this->whenLoaded('author', fn () => $this->author?->name),

            ...$this->stamps(),
        ];
    }
}
