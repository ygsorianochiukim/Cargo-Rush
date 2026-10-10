<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTO;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\JournalCategory;

final class JournalEntryData extends Data
{
    /** @param JournalLineData[] $lines */
    public function __construct(
        public readonly ?string $entry_date = null,
        public readonly ?JournalCategory $category = null,
        public readonly ?string $memo = null,
        public readonly array $lines = [],
        public readonly ?string $status = null,
        public readonly ?string $source = null,
        public readonly ?string $source_type = null,
        public readonly ?string $source_id = null,
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

    /** @return array<string, mixed> */
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

    public function difference(): int
    {
        return $this->totalDebits() - $this->totalCredits();
    }

    public function isBalanced(): bool
    {
        return $this->difference() === 0;
    }

    public function wantsPosting(): bool
    {
        return $this->status === JournalEntry::POSTED;
    }
}
