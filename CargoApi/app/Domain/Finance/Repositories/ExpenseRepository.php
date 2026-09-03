<?php

declare(strict_types=1);

namespace App\Domain\Finance\Repositories;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class ExpenseRepository extends Repository
{
    protected function model(): string
    {
        return Expense::class;
    }

    /**
     * Expenses always travel with the names a row needs to render, so no list
     * view can fall into an N+1.
     */
    public function query(): Builder
    {
        return Expense::query()
            ->with(['category:id,key,name,icon', 'truck:id,label,plate', 'driver:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('created_at');
    }

    protected function searchable(): array
    {
        return ['payee', 'reference', 'note'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['category_id'])) {
            $query->whereIn('category_id', (array) $filters['category_id']);
        }

        if (! empty($filters['truck_id'])) {
            $query->where('truck_id', $filters['truck_id']);
        }

        if (! empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (! empty($filters['trip_id'])) {
            $query->where('trip_id', $filters['trip_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', Carbon::parse($filters['to'])->toDateString());
        }

        return $query;
    }

    /**
     * Counted spend in a window.
     *
     * The one query every roll-up starts from. Built on the bare model rather
     * than `query()`, because an aggregate over this carries no eager loads and
     * no ordering — a `group by` beside an `order by` on a column it does not
     * name is what MySQL's `ONLY_FULL_GROUP_BY` refuses to run.
     *
     * @return Collection<int, Expense>
     */
    public function between(Carbon $from, Carbon $to): Collection
    {
        return Expense::query()
            ->counted()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
    }

    /**
     * Counted spend per category in a window, biggest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function totalsByCategory(Carbon $from, Carbon $to): array
    {
        $spend = $this->between($from, $to)->groupBy('category_id');

        return $this->categories()
            ->map(function (ExpenseCategory $category) use ($spend): array {
                $mine = $spend->get($category->id, collect());

                return [
                    'category' => [
                        'id' => $category->id,
                        'key' => $category->key,
                        'name' => $category->name,
                        'icon' => $category->icon,
                    ],
                    'amount_cents' => (int) $mine->sum('amount_cents'),
                    'entry_count' => $mine->count(),
                ];
            })
            // Categories with no spend in the window are dropped: a report of
            // where the money went should not be mostly zeroes.
            ->filter(static fn (array $row): bool => $row['entry_count'] > 0)
            ->sortByDesc('amount_cents')
            ->values()
            ->all();
    }

    /** @return Collection<int, ExpenseCategory> */
    public function categories(bool $activeOnly = false): Collection
    {
        return ExpenseCategory::query()
            ->when($activeOnly, static fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    public function findCategoryByKey(string $key): ?ExpenseCategory
    {
        return ExpenseCategory::where('key', $key)->first();
    }
}
