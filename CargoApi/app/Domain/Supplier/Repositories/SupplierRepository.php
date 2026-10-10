<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class SupplierRepository extends Repository
{
    protected function model(): string
    {
        return Supplier::class;
    }

    public function query(): Builder
    {
        return Supplier::query()
            ->with('category')
            ->withSum([
                'expenses as expense_spend_cents' => fn (Builder $q) => $q
                    ->where('status', StatusValue::Active->value),
            ], 'amount_cents')
            ->withSum([
                'maintenanceJobs as service_spend_cents' => fn (Builder $q) => $q
                    ->whereNotNull('completed_on')
                    ->where('posted_cents', '>', 0),
            ], 'posted_cents')
            ->withSum([
                'bills as billed_cents' => fn (Builder $q) => $q
                    ->where('status', '!=', StatusValue::Cancelled->value),
            ], 'amount_cents')
            ->withCount('expenses')
            ->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'contact', 'supplies'];
    }

    /** @param array<string, mixed> $filters */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['active'])) {
            $query->active();
        }

        return $query;
    }
}
