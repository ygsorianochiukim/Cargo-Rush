<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Repositories;

use App\Domain\Fuel\Models\FuelBudget;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class FuelRepository extends Repository
{
    protected function model(): string
    {
        return FuelRecord::class;
    }

    public function query(): Builder
    {
        return FuelRecord::query()
            ->with(['vehicle:id,plate', 'driver:id,name'])
            ->orderByDesc('logged_at');
    }

    protected function searchable(): array
    {
        return ['receipt_no'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('logged_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('logged_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }

    /** Today's allowance row, or null if nobody has set one. */
    public function budgetFor(Carbon $date): ?FuelBudget
    {
        return FuelBudget::query()->whereDate('date', $date)->first();
    }

    /**
     * What was spent: active fills only — the same rule as the Finance
     * roll-up, so the Fuel page's "spent" and a period's fuel agree.
     *
     * It used to count pending ones too, so the budget tile ran ahead of
     * Profitability by every request nobody had approved yet. Those are
     * `pendingBetween()`, shown beside it rather than inside it.
     */
    public function spentBetween(Carbon $from, Carbon $to): int
    {
        return (int) FuelRecord::query()
            ->whereBetween('logged_at', [$from, $to])
            ->where('status', StatusValue::Active->value)
            ->sum('amount_cents');
    }

    /** Requested and not yet approved — committed, not spent. */
    public function pendingBetween(Carbon $from, Carbon $to): int
    {
        return (int) FuelRecord::query()
            ->whereBetween('logged_at', [$from, $to])
            ->where('status', StatusValue::Pending->value)
            ->sum('amount_cents');
    }

    /**
     * Fills that have posted themselves onto a sheet row in the window, by the
     * day they posted to.
     *
     * @return Collection<int, FuelRecord>
     */
    public function postedBetween(Carbon $from, Carbon $to): Collection
    {
        return FuelRecord::query()
            ->where('posted_cents', '>', 0)
            ->whereNotNull('posted_truck_id')
            ->whereDate('posted_on', '>=', $from->toDateString())
            ->whereDate('posted_on', '<=', $to->toDateString())
            ->get();
    }

    /**
     * The fills a period's Total expenses counts: active ones only.
     *
     * Stricter than `spentBetween()` on purpose. A pending fill is a request
     * nobody has approved, and the roll-up is what the period actually spent.
     * Built on the bare model, like `ExpenseRepository::between()`, because an
     * aggregate over this wants no eager loads and no ordering.
     *
     * @return Collection<int, FuelRecord>
     */
    public function countedBetween(Carbon $from, Carbon $to): Collection
    {
        return FuelRecord::query()
            ->whereBetween('logged_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('status', StatusValue::Active->value)
            ->get();
    }

    /**
     * The counted fills that are **not** on any sheet row — the ones a roll-up
     * still has to add by hand.
     *
     * A posted fill is already inside its row's `fuel_cents`, and adding it
     * again is the double count `/fuel` being the one source exists to end.
     * What is left is a fill for a vehicle no truck points at (overhead) and
     * anything logged before fills posted themselves and not yet backfilled.
     *
     * @return Collection<int, FuelRecord>
     */
    public function unpostedBetween(Carbon $from, Carbon $to): Collection
    {
        return $this->countedBetween($from, $to)
            ->filter(static fn (FuelRecord $fill): bool => ! $fill->isPosted())
            ->values();
    }

    public function openRequests(): int
    {
        return FuelRecord::query()->where('status', StatusValue::Pending->value)->count();
    }
}
