<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Model;

/**
 * A categorised expense line — and a flat-rented truck's monthly rent, which
 * `TruckRentService` files as one.
 *
 * Dr the category's account (`ExpenseCategory::accountCode()`), Cr cash, on the
 * line's date. Only a line Finance counts (`Expense::counted()`, which is
 * `active`): a `pending` one is spend nobody has settled, which the Payables
 * page lists as owed and no period has paid for yet.
 */
class ExpenseRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var Expense $expense */
        $expense = $source;

        if ($expense->status !== StatusValue::Active || $expense->date === null) {
            return [];
        }

        // A line on a truck the fleet no longer lists is in no roll-up row.
        if ($expense->truck_id !== null && ! Truck::query()->whereKey($expense->truck_id)->exists()) {
            return [];
        }

        $category = ExpenseCategory::query()->withTrashed()->find($expense->category_id);
        $account = $category?->accountCode() ?? ExpenseCategory::FALLBACK_ACCOUNT;
        $kind = $category?->name ?? 'Expense';

        return [
            $this->posting(
                'expense',
                $expense->date,
                JournalCategory::Disbursement,
                trim(sprintf('%s · %s', $kind, $expense->payee ?? $expense->reference ?? $expense->note ?? ''), ' ·'),
            )->about(['truck_id' => $expense->truck_id, 'trip_id' => $expense->trip_id])
                ->debit($account, (int) $expense->amount_cents, $kind)
                ->credit($this->code('cash'), (int) $expense->amount_cents, $kind),
        ];
    }

    public function label(Model $source): string
    {
        /** @var Expense $source */
        return 'expense '.($source->reference ?? $source->payee ?? $source->getKey());
    }
}
