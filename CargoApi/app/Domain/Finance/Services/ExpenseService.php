<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DTO\ExpenseCategoryData;
use App\Domain\Finance\DTO\ExpenseData;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Shared\Enums\PaySchedule;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use App\Domain\Tenancy\Support\RateBook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ExpenseService extends CrudService
{
    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly FinanceService $finance,
        private readonly RateBook $rates,
    ) {}

    protected function repository(): Repository
    {
        return $this->expenses;
    }

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

        return $this->attachToLedger($updated);
    }

    private function attachToLedger(Expense $expense): Expense
    {
        if ($expense->truck_id === null) {
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

    /** @return Collection<int, ExpenseCategory> */
    public function categories(bool $activeOnly = false)
    {
        return $this->expenses->categories($activeOnly);
    }

    public function createCategory(ExpenseCategoryData $data): ExpenseCategory
    {
        $attributes = $data->persistable();

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

    public function deleteCategory(ExpenseCategory $category): bool
    {
        if ($category->expenses()->exists()) {
            $category->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $category->delete();

        return true;
    }

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

    /** @return array<string, mixed> */
    public function report(Carbon $from, Carbon $to): array
    {
        $rows = $this->expenses->totalsByCategory($from, $to);
        $spend = $this->expenses->between($from, $to);

        $total = (int) $spend->sum('amount_cents');
        $overhead = (int) $spend->whereNull('truck_id')->sum('amount_cents');
        $vatable = $spend->where('is_vatable', true);
        $rateBp = $this->rates->vatRateBp();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'categories' => $rows,
            'total_cents' => $total,
            'overhead_cents' => $overhead,
            'attributed_cents' => $total - $overhead,
            'vatable_cents' => (int) $vatable->sum('amount_cents'),
            'non_vatable_cents' => $total - (int) $vatable->sum('amount_cents'),
            'input_vat_cents' => (int) $vatable->sum(static fn (Expense $e): int => $e->vatCents($rateBp)),
            'vat_rate_bp' => $rateBp,
            'by_schedule' => array_map(static fn (PaySchedule $schedule): array => [
                'schedule' => $schedule->value,
                'label' => $schedule->label(),
                'amount_cents' => (int) $spend
                    ->filter(static fn (Expense $e): bool => ($e->pay_schedule ?? PaySchedule::OnReceipt) === $schedule)
                    ->sum('amount_cents'),
            ], PaySchedule::cases()),
            'entry_count' => $spend->count(),
            'currency' => 'PHP',
        ];
    }
}
