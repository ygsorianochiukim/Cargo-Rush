<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Finance\Services\PayrollCostService;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\JournalCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The part of a paid run the daily sheet already charged.
 *
 * Payroll posts itself (`PayrollService::post`): the whole gross to wages, the
 * net to cash, the withholdings to their payables. That entry is payroll's and
 * is not touched here. But the sheet's driver and helper columns are the same
 * crew's pay for the same days, and `SheetDayRule` has already charged them to
 * expense (against 2110, accrued) — so the run's gross on top would charge the
 * fleet twice for one fortnight of driving.
 *
 * Finance settles that per payslip (`PayrollCostService`): a run adds only
 * what the sheet never saw. This posts the difference so the books agree:
 *
 *     Dr 2110 Crew pay accrued from the daily sheet
 *       Cr 5020 Driver salaries (payroll's crew-wages account)
 *
 * — the accrual the sheet made is what the run paid, and the expense stays
 * once. The amount is what payroll's own entry charged to expense less what
 * Finance counts of the run, so whatever the run's entry carries (employer
 * contributions included) is matched to the peso. Dated the run's pay date, as
 * Finance dates it; only a paid run whose payroll entry is posted.
 */
class PayRunRule extends PostingRule
{
    public function __construct(private readonly PayrollCostService $payroll) {}

    public function postings(Model $source): array
    {
        /** @var PayRun $run */
        $run = $source;

        if (! $run->isPaid() || $run->journal_entry_id === null) {
            return [];
        }

        $entry = JournalEntry::query()
            ->with('lines.account')
            ->whereKey($run->journal_entry_id)
            ->where('status', JournalEntry::POSTED)
            ->first();

        if ($entry === null) {
            return [];
        }

        $charged = (int) $entry->lines
            ->filter(static fn ($line): bool => $line->account?->type === AccountType::Expense)
            ->sum(static fn ($line): int => (int) $line->debit_cents - (int) $line->credit_cents);

        $day = Carbon::parse(($run->pay_date ?? $run->paid_at ?? $run->updated_at ?? now())->toDateString());

        $counted = $this->payroll->paidBetween($day, $day)
            ->first(static fn (array $row): bool => $row['run']->getKey() === $run->getKey());

        if ($counted === null) {
            return [];
        }

        $overlap = $charged - (int) $counted['counted_cents'];
        $wages = (string) config('cargo.payroll.accounts.crew_wages_expense', '5020');

        return [
            $this->posting(
                'sheet-overlap',
                Carbon::parse($counted['date']),
                JournalCategory::Payroll,
                sprintf('Payroll %s · crew pay already on the daily sheet', $run->reference),
            )->debit($this->code('crew_pay_accrued'), $overlap, 'Paid by this run, charged on the sheet')
                ->credit($wages, $overlap, 'Already an expense on the daily sheet'),
        ];
    }

    public function label(Model $source): string
    {
        /** @var PayRun $source */
        return 'pay run '.$source->reference;
    }
}
