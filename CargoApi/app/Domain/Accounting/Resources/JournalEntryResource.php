<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Resources;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One entry in the general journal, with its two sides.
 *
 * The lines always come with it. An entry without them is a row that cannot be
 * read — the whole content of a journal entry is which accounts moved and by
 * how much — so there is no "summary" shape of this resource and no second call
 * to fetch the detail.
 *
 * The totals are computed from the lines rather than stored (see
 * `JournalEntry`), which is why they are here on every read: the client should
 * not add up a list to find out whether the entry it is showing balances.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $lines = $this->lines ?? collect();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            /** A date with no time — the day the transaction belongs to. */
            'entry_date' => $this->entry_date?->toDateString(),

            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            /** An icon name from the shared set, never a colour. */
            'category_icon' => $this->category->icon(),

            'memo' => $this->memo,
            'status' => $this->status,

            /**
             * The two totals, and whether they agree.
             *
             * `balanced` is false only for a draft somebody is still writing:
             * nothing unbalanced can be posted. It is what the form's own
             * footer reads, so the check the API applies and the check the
             * screen shows are the same number.
             */
            'debit_cents' => $this->totalDebits(),
            'credit_cents' => $this->totalCredits(),
            'balanced' => $this->isBalanced(),
            'currency' => 'PHP',

            /** `manual`, or the document that posted itself. */
            'source' => $this->source,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            // Posted by a record rather than by somebody at a keyboard, and
            // kept true by it — correct the record, not the entry.
            'automatic' => $this->resource->isAutomatic(),
            'source_label' => AutoPostingService::sourceLabel($this->source_type),

            'posted_at' => $this->iso($this->posted_at),
            'posted_by_name' => $this->postedBy?->name,
            'voided_at' => $this->iso($this->voided_at),
            'void_reason' => $this->void_reason,

            /**
             * What the client may offer.
             *
             * Sent rather than derived from the status in each client, because
             * the rules are the API's: a draft can be edited, posted and
             * deleted; a posted entry can only be voided; a void one is
             * finished. Two clients working it out from `status` is two places
             * to get it wrong, and a button that only ever returns a 422 is
             * worse than no button.
             */
            'can_edit' => $this->isDraft(),
            'can_post' => $this->isDraft(),
            'can_void' => $this->isPosted(),

            'lines' => $lines->map(static fn (JournalLine $line): array => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'account_id' => $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'account_type' => $line->account?->type->value,
                /** `debit` or `credit`. A line is always on exactly one side. */
                'side' => $line->side()->value,
                'debit_cents' => $line->debit_cents,
                'credit_cents' => $line->credit_cents,
                'amount_cents' => $line->amountCents(),
                'memo' => $line->memo,
                'truck_id' => $line->truck_id,
                'trip_id' => $line->trip_id,
                'customer_id' => $line->customer_id,
            ])->values()->all(),

            ...$this->stamps(),
        ];
    }
}
