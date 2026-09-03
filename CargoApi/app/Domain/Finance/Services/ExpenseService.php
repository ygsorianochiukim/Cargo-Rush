<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DTO\ExpenseCategoryData;
use App\Domain\Finance\DTO\ExpenseData;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Categorised spend — the "what did it go on" half of Finance.
 *
 * Two things here are not plain CRUD, and both are about keeping this module
 * honest against the ledger it feeds:
 *
 *   - An expense filed against a truck on a date attaches itself to that
 *     truck's sheet for the day, creating the row if the day has none. The
 *     alternative is spend that exists in one report and not the other.
 *   - A category with rows against it is retired, never deleted, because
 *     deleting it would take the spend with it and a closed quarter would
 *     quietly stop balancing.
 */
class ExpenseService extends CrudService
{
    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly FinanceService $finance,
    ) {}

    protected function repository(): Repository
    {
        return $this->expenses;
    }

    /**
     * File an expense, and put it on the day's sheet if it names a truck.
     *
     * The ledger row is opened rather than written to: the five workbook
     * columns are the office's to key in, and this module has no business
     * moving them. What the link buys is that Daily Trip Monitoring can show
     * the day's categorised spend beside its columns instead of the two views
     * disagreeing about what a Tuesday cost.
     */
    public function record(ExpenseData $data, ?int $userId): Expense
    {
        /** @var Expense $expense */
        $expense = $this->expenses->create($data);

        if ($expense->recorded_by === null && $userId !== null) {
            $expense->forceFill(['recorded_by' => $userId])->save();
        }

        return $this->attachToLedger($expense);
    }

    public function updateExpense(Expense $expense, ExpenseData $data): Expense
    {
        /** @var Expense $updated */
        $updated = $this->expenses->update($expense, $data);

        // The truck or the date may have moved, which means the day's sheet it
        // belongs to has too. Re-deriving is cheaper than keeping a stale link
        // that puts a Tuesday's fuel on a Monday.
        return $this->attachToLedger($updated);
    }

    private function attachToLedger(Expense $expense): Expense
    {
        if ($expense->truck_id === null) {
            // Overhead has no unit and therefore no sheet. It still counts in
            // the period total; `FinanceService` is where that happens.
            return $expense;
        }

        $row = $this->finance->openDailyRowForTruck(
            truckId: $expense->truck_id,
            date: $expense->date ?? Carbon::now(),
        );

        if ($expense->ledger_entry_id !== $row->id) {
            $expense->forceFill(['ledger_entry_id' => $row->id])->save();
        }

        return $expense->refresh();
    }

    /* --------------------------------------------------------- Categories */

    /** @return Collection<int, ExpenseCategory> */
    public function categories(bool $activeOnly = false)
    {
        return $this->expenses->categories($activeOnly);
    }

    public function createCategory(ExpenseCategoryData $data): ExpenseCategory
    {
        $attributes = $data->persistable();

        // Slugged from the name when the caller offered none. Done here rather
        // than in the DTO so it happens on create only — re-deriving it on a
        // rename would break every seeded lookup that goes through the key.
        if (empty($attributes['key'])) {
            $attributes['key'] = $this->uniqueKey(Str::slug((string) ($attributes['name'] ?? 'category')));
        }

        return ExpenseCategory::create($attributes)->refresh();
    }

    public function updateCategory(ExpenseCategory $category, ExpenseCategoryData $data): ExpenseCategory
    {
        $category->update($data->persistable());

        return $category->refresh();
    }

    /**
     * Remove a category, or retire it if anything is filed against it.
     *
     * @return bool true when it was deleted, false when it was retired instead
     */
    public function deleteCategory(ExpenseCategory $category): bool
    {
        if ($category->expenses()->exists()) {
            $category->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $category->delete();

        return true;
    }

    /** "food", then "food-2" — a name somebody reuses must not collide. */
    private function uniqueKey(string $base): string
    {
        $base = $base === '' ? 'category' : $base;
        $key = $base;
        $suffix = 2;

        while ($this->expenses->findCategoryByKey($key) !== null) {
            $key = "$base-".$suffix++;
        }

        return $key;
    }

    /* ------------------------------------------------------------ Reports */

    /**
     * Where the money went, over a window.
     *
     * @return array<string, mixed>
     */
    public function report(Carbon $from, Carbon $to): array
    {
        $rows = $this->expenses->totalsByCategory($from, $to);
        $spend = $this->expenses->between($from, $to);

        $total = (int) $spend->sum('amount_cents');
        $overhead = (int) $spend->whereNull('truck_id')->sum('amount_cents');

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'categories' => $rows,
            'total_cents' => $total,
            // Split out because it is the figure that behaves differently:
            // it counts against the period but against no truck.
            'overhead_cents' => $overhead,
            'attributed_cents' => $total - $overhead,
            'entry_count' => $spend->count(),
            'currency' => 'PHP',
        ];
    }
}
