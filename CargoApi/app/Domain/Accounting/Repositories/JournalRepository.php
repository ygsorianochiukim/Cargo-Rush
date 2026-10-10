<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Repositories;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class JournalRepository extends Repository
{
    protected function model(): string
    {
        return JournalEntry::class;
    }

    public function query(): Builder
    {
        return JournalEntry::query()
            ->with(['lines.account:id,code,name,type', 'postedBy:id,name'])
            ->inJournalOrder();
    }

    protected function searchable(): array
    {
        return ['reference', 'memo'];
    }

    /** @param array<string, mixed> $filters */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['category'])) {
            $query->whereIn('category', (array) $filters['category']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }

        if (! empty($filters['account_id'])) {
            $query->whereHas(
                'lines',
                fn (Builder $lines): Builder => $lines->where('account_id', $filters['account_id']),
            );
        }

        return $query;
    }
}
