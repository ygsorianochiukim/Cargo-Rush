<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * Settling up: a payout, a remittance, or a correction.
 *
 * One request for the three because they are one form at the desk — pick what
 * this is, type a figure, give it a reference. What differs is which direction
 * it moves the balance and what it is allowed to exceed, and both of those are
 * the service's to enforce rather than the validator's: they depend on what the
 * balance currently is, which is a question about the database and not about
 * the payload.
 *
 * `earning` and `commission` are deliberately not offered. Those are written by
 * a delivery and by nothing else — a desk that could hand-write an earning
 * could credit a partner for a run that never happened, and the trip is the
 * only honest authority for what was hauled.
 */
class WalletEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:'.implode(',', [
                WalletEntryKind::Payout->value,
                WalletEntryKind::Remittance->value,
                WalletEntryKind::Adjustment->value,
            ])],

            /**
             * The runs being settled — a payout and a remittance only.
             *
             * **There is no amount on a settlement any more.** It is derived
             * from the rows it covers, so the money handed over and the runs it
             * paid for cannot disagree. An empty array, or the field left out
             * altogether, means every outstanding run — which is the ordinary
             * case and the one button the office presses.
             *
             * Not validated with `exists`: the rows are tenant-scoped and must
             * also belong to *this* partner and still be outstanding, none of
             * which a rule here can check. `WalletService::settle()` does all
             * three and says which failed.
             */
            'entry_ids' => ['sometimes', 'array'],
            'entry_ids.*' => ['string', 'max:26'],

            /**
             * Centavos, signed, and **only** for an adjustment.
             *
             * The one kind that still carries a figure, because a correction is
             * by definition not about a run. Required for it and refused on the
             * other two — see `withValidator()`.
             */
            'amount_cents' => ['sometimes', 'integer', 'not_in:0'],

            /**
             * How the money went out. Free text, like `payments.method`:
             * the list is a business's own and grows.
             */
            'method' => ['sometimes', 'string', 'max:40'],

            /**
             * Has it landed already?
             *
             * False unless said otherwise, because the default method is a
             * transfer and a transfer takes a day. Cash across a desk is the
             * case that sets it true — it has arrived by the time anybody
             * types it. Until it is true the balance does not move.
             */
            'cleared' => ['sometimes', 'boolean'],

            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
            'occurred_on' => ['nullable', 'date'],
        ];
    }

    /**
     * The two rules that depend on which kind this is.
     *
     * Here rather than in `rules()` because both are conditional, and
     * `required_if` would answer with the default wording ("The note field is
     * required when kind is adjustment"), which reads like a schema complaint
     * rather than the actual requirement.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $adjustment = $this->input('kind') === WalletEntryKind::Adjustment->value;

            if ($adjustment) {
                // A correction that does not say what it corrects is not a
                // record of anything.
                if (trim((string) $this->input('note')) === '') {
                    $validator->errors()->add('note', 'Say what this adjustment is for.');
                }

                if ($this->input('amount_cents') === null) {
                    $validator->errors()->add('amount_cents', 'Enter the amount to adjust by.');
                }

                return;
            }

            /**
             * A payout or a remittance carries no figure of its own.
             *
             * Refused rather than ignored: a desk sending both an amount and a
             * set of runs has two ideas about what is being paid, and quietly
             * honouring the runs would hand over a different sum from the one
             * on screen.
             */
            if ($this->input('amount_cents') !== null) {
                $validator->errors()->add(
                    'amount_cents',
                    'A payout is made up of the runs it covers. Pick the runs rather than typing a figure.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'amount_cents.not_in' => 'Enter an amount.',
        ];
    }

    /**
     * The runs to settle. Empty means all of them.
     *
     * @return string[]
     */
    public function entryIds(): array
    {
        return array_values(array_filter(
            array_map(trim(...), (array) $this->input('entry_ids', [])),
            static fn (string $id): bool => $id !== '',
        ));
    }
}
