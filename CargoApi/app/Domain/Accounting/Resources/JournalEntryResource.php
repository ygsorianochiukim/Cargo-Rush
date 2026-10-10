<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Resources;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin JournalEntry */
class JournalEntryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $lines = $this->lines ?? collect();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'entry_date' => $this->entry_date?->toDateString(),

            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'category_icon' => $this->category->icon(),

            'memo' => $this->memo,
            'status' => $this->status,

            'debit_cents' => $this->totalDebits(),
            'credit_cents' => $this->totalCredits(),
            'balanced' => $this->isBalanced(),
            'currency' => 'PHP',

            'source' => $this->source,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'automatic' => $this->resource->isAutomatic(),
            'source_label' => AutoPostingService::sourceLabel($this->source_type),

            'posted_at' => $this->iso($this->posted_at),
            'posted_by_name' => $this->postedBy?->name,
            'voided_at' => $this->iso($this->voided_at),
            'void_reason' => $this->void_reason,

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
