<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trucker\Models\WalletEntry;
use Illuminate\Http\Request;

/**
 * One line of the statement.
 *
 * @mixin WalletEntry
 */
class WalletEntryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            // The sentence, built server-side so the handset, the office tab
            // and the export cannot drift into three wordings of the same row.
            'description' => $this->describe(),

            /**
             * Signed centavos — positive towards the partner, negative towards
             * the haulier. The client renders the sign; it does not decide it.
             */
            'amount_cents' => $this->amount_cents,

            /**
             * Where the work came from, and the whole reason this column
             * exists: a partner reading the line a year later can see whether
             * it was the haulier's job or their own, which is what makes the
             * direction of the amount make sense.
             */
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),

            // What the run billed and what was taken off it, frozen at the
            // moment this row was written.
            'gross_cents' => $this->gross_cents,
            'rate_bp' => $this->rate_bp,

            'trip_id' => $this->trip_id,
            'trip_reference' => $this->whenLoaded('trip', fn () => $this->trip?->reference),

            'reference' => $this->reference,
            'note' => $this->note,
            'occurred_on' => $this->occurred_on?->toDateString(),

            /**
             * Has this run been paid for?
             *
             * The column both screens lead with now. Only meaningful on a work
             * row — a payout is the payment rather than something awaiting one,
             * and an adjustment is a correction to the balance — so it answers
             * false for those, and neither is ever offered for settlement.
             *
             * `settlement_reference` is the cheque or transfer number, which is
             * the thing a partner actually checks against their own records.
             */
            'settleable' => $this->kind->isFromWork(),
            'settled' => $this->isSettled(),
            'settled_at' => $this->iso($this->settled_at),
            'settlement_reference' => $this->whenLoaded(
                'settlement',
                fn () => $this->settlement?->reference,
            ),

            /**
             * Three states on a run, not two.
             *
             * `unpaid` — nobody has started paying for it.
             * `processing` — the office has sent the money; it has not landed.
             * `paid` — it arrived.
             *
             * Derived here rather than on three clients, because it is two
             * columns on two rows — this row's `settled_by`, and the
             * settlement's own status — and three places working that out is
             * three chances to tell somebody they have been paid when the
             * transfer is still in the air.
             */
            'payment_state' => $this->paymentState(),

            /** On a payment row itself: where it has got to, and how it went. */
            'status' => $this->status->value,
            'method' => $this->method,

            ...$this->stamps(),
        ];
    }
}
