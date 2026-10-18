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

    public function query(): Builder
    {
        return Expense::query()
            ->with([
                'category:id,key,name,icon',
                'truck:id,label,plate',
                'driver:id,name',
                'supplier:id,name',
                'paymentSource:id,name,kind,last_four',
                'trip:id,reference',
            ])
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

        if (! empty($filters['pay_schedule'])) {
            $query->whereIn('pay_schedule', (array) $filters['pay_schedule']);
        }

        if (! empty($filters['payment_source_id'])) {
            $query->where('payment_source_id', $filters['payment_source_id']);
        }

        if (isset($filters['vatable']) && $filters['vatable'] !== '') {
            $query->where('is_vatable', filter_var($filters['vatable'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', Carbon::parse($filters['to'])->toDateString());
        }

        return $query;
    }

    /** @return Collection<int, Expense> */
    public function between(Carbon $from, Carbon $to): Collection
    {
        return Expense::query()
            ->counted()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
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
            ->filter(static fn (array $row): bool => $row['entry_count'] > 0)
            ->sortByDesc('amount_cents')
            ->values()
            ->all();
    }

    /** @return Collection<int, ExpenseCategory> */
    public function categories(bool $activeOnly = false): Collection
    {
        return ExpenseCategory::query()
            ->withCount('expenses')
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
