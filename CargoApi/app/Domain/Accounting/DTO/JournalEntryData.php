<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTO;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\JournalCategory;

/**
 * A journal entry and its sides, on their way in.
 *
 * The one DTO in the system that carries a collection of other DTOs, and it has
 * to: an entry and its lines are one act. Writing the head and then the lines
 * in two calls would allow a moment where an entry exists with no sides — a row
 * that is not a transaction — and a failure between the two would leave it there
 * for good.
 *
 * The arithmetic is here rather than in the service because it is a property of
 * the payload, not of the writing: `isBalanced()` is what the request validator
 * asks so the caller gets a 422 naming the difference, and what the service
 * asks again before it posts. Two checks of one rule, on purpose — the second
 * is what makes it true for a console command or an automatic posting that
 * never went near a form.
 */
final class JournalEntryData extends Data
{
    /**
     * @param  JournalLineData[]  $lines
     */
    public function __construct(
        public readonly ?string $entry_date = null,
        public readonly ?JournalCategory $category = null,
        public readonly ?string $memo = null,
        public readonly array $lines = [],
        /**
         * `draft` or `posted`.
         *
         * A form can save either — an accountant part-way through a compound
         * entry saves a draft, and one keying yesterday's fuel posts it
         * straight away. What no payload can set is `void`: withdrawing an
         * entry is its own act with its own reason, not a status somebody
         * PATCHes. See `JournalService::void()`.
         */
        public readonly ?string $status = null,
        /** `manual`, or the document that posted itself. */
        public readonly ?string $source = null,
        public readonly ?string $source_type = null,
        public readonly ?string $source_id = null,
        /** Which posting of the source this is, and which version of it. See the migration. */
        public readonly ?string $source_rule = null,
        public readonly ?int $source_revision = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            entry_date: $attributes['entry_date'] ?? null,
            category: isset($attributes['category'])
                ? JournalCategory::from((string) $attributes['category'])
                : null,
            memo: $attributes['memo'] ?? null,
            lines: array_values(array_map(
                static fn (array $line): JournalLineData => JournalLineData::fromArray($line),
                $attributes['lines'] ?? [],
            )),
            status: $attributes['status'] ?? null,
            source: $attributes['source'] ?? null,
            source_type: $attributes['source_type'] ?? null,
            source_id: $attributes['source_id'] ?? null,
            source_rule: $attributes['source_rule'] ?? null,
            source_revision: isset($attributes['source_revision']) ? (int) $attributes['source_revision'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'entry_date' => $this->entry_date,
            'category' => $this->category?->value,
            'memo' => $this->memo,
            'lines' => array_map(static fn (JournalLineData $line): array => $line->toArray(), $this->lines),
            'status' => $this->status,
            'source' => $this->source,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_rule' => $this->source_rule,
            'source_revision' => $this->source_revision,
        ];
    }

    /**
     * The `journal_entries` columns — everything except the lines.
     *
     * `persistable()` handles the create-versus-patch distinction for the
     * scalar fields; the lines are replaced wholesale rather than merged, which
     * is what editing an entry means (see `JournalService::update()`).
     *
     * @return array<string, mixed>
     */
    public function head(): array
    {
        $columns = $this->persistable();

        unset($columns['lines']);

        return $columns;
    }

    public function totalDebits(): int
    {
        return array_sum(array_map(
            static fn (JournalLineData $line): int => $line->debitCents(),
            $this->lines,
        ));
    }

    public function totalCredits(): int
    {
        return array_sum(array_map(
            static fn (JournalLineData $line): int => $line->creditCents(),
            $this->lines,
        ));
    }

    /** Debits less credits. Zero, or the entry is not a transaction. */
    public function difference(): int
    {
        return $this->totalDebits() - $this->totalCredits();
    }

    public function isBalanced(): bool
    {
        return $this->difference() === 0;
    }

    /** True when the caller means to put this in the books now. */
    public function wantsPosting(): bool
    {
        return $this->status === JournalEntry::POSTED;
    }
}
