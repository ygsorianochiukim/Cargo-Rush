<?php

declare(strict_types=1);

namespace App\Domain\Asset\Repositories;

use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class AssetRepository extends Repository
{
    protected function model(): string
    {
        return Asset::class;
    }

    public function query(): Builder
    {
        return Asset::query()
            ->with(['category', 'supplier', 'custodian'])
            ->orderByDesc('created_at')
            ->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'tag', 'serial_no', 'location'];
    }

    /** @param array<string, mixed> $filters */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['custodian_id'])) {
            $query->where('custodian_id', $filters['custodian_id']);
        }

        return $query;
    }
}
