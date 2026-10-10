<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\BalanceSide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeneralLedgerService
{
    /** @return array<string, mixed> */
    public function forAccount(Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $opening = $this->openingBalance($account, $from);

        $lines = $this->postings()
            ->select('journal_lines.*')
            ->where('journal_lines.account_id', $account->getKey())
            ->when($from !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '<=', $to))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.reference')
            ->orderBy('journal_lines.line_no')
            ->with(['entry:id,reference,entry_date,category,memo,status'])
            ->get();

        $normal = $account->normalBalance();
        $running = $opening;
        $debits = 0;
        $credits = 0;

        $rows = $lines->map(function (JournalLine $line) use ($normal, &$running, &$debits, &$credits): array {
            $debits += $line->debit_cents;
            $credits += $line->credit_cents;

            $running += $normal->sign(BalanceSide::Debit) * $line->debit_cents
                + $normal->sign(BalanceSide::Credit) * $line->credit_cents;

            return [
                'line_id' => $line->getKey(),
                'entry_id' => $line->journal_entry_id,
                'reference' => $line->entry?->reference,
                'entry_date' => $line->entry?->entry_date?->toDateString(),
                'category' => $line->entry?->category?->value,
                'memo' => $line->memo ?? $line->entry?->memo,
                'debit_cents' => $line->debit_cents,
                'credit_cents' => $line->credit_cents,
                'balance_cents' => $running,
                'truck_id' => $line->truck_id,
                'trip_id' => $line->trip_id,
                'customer_id' => $line->customer_id,
            ];
        })->all();

        return [
            'account' => [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'group' => $account->group,
                'normal_balance' => $normal->value,
            ],
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'opening_balance_cents' => $opening,
            'debit_cents' => $debits,
            'credit_cents' => $credits,
            'movement_cents' => $running - $opening,
            'closing_balance_cents' => $running,
            'entry_count' => $lines->pluck('journal_entry_id')->unique()->count(),
            'lines' => $rows,
            'currency' => 'PHP',
        ];
    }

    /** @return array<string, mixed> */
    public function trialBalance(?Carbon $asOf = null): array
    {
        $totals = $this->balances(null, $asOf);

        $accounts = Account::query()->inChartOrder()->get();
        $rows = [];
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($accounts as $account) {
            $balance = $totals[$account->getKey()] ?? null;

            if ($balance === null) {
                continue;
            }

            $normal = $account->normalBalance();
            $signed = $normal->sign(BalanceSide::Debit) * $balance['debit']
                + $normal->sign(BalanceSide::Credit) * $balance['credit'];

            if ($signed === 0) {
                continue;
            }

            $onDebitSide = $normal === BalanceSide::Debit ? $signed >= 0 : $signed < 0;
            $magnitude = abs($signed);

            $debitTotal += $onDebitSide ? $magnitude : 0;
            $creditTotal += $onDebitSide ? 0 : $magnitude;

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'group' => $account->group,
                'debit_cents' => $onDebitSide ? $magnitude : 0,
                'credit_cents' => $onDebitSide ? 0 : $magnitude,
                'balance_cents' => $signed,
            ];
        }

        return [
            'as_of' => ($asOf ?? Carbon::now())->toDateString(),
            'rows' => $rows,
            'debit_total_cents' => $debitTotal,
            'credit_total_cents' => $creditTotal,
            'balanced' => $debitTotal === $creditTotal,
            'difference_cents' => $debitTotal - $creditTotal,
            'currency' => 'PHP',
        ];
    }

    /** @return array<string, mixed> */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $totals = $this->balances($from, $to);
        $accounts = Account::query()->inChartOrder()->get();

        $groups = [];

        foreach (AccountType::cases() as $type) {
            $groups[$type->value] = [
                'type' => $type->value,
                'label' => $type->label(),
                'normal_balance' => $type->normalBalance()->value,
                'accounts' => [],
                'debit_cents' => 0,
                'credit_cents' => 0,
                'balance_cents' => 0,
            ];
        }

        foreach ($accounts as $account) {
            $balance = $totals[$account->getKey()] ?? ['debit' => 0, 'credit' => 0];
            $normal = $account->normalBalance();
            $signed = $normal->sign(BalanceSide::Debit) * $balance['debit']
                + $normal->sign(BalanceSide::Credit) * $balance['credit'];

            $key = $account->type->value;

            $groups[$key]['accounts'][] = [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'group' => $account->group,
                'status' => $account->status->value,
                'debit_cents' => $balance['debit'],
                'credit_cents' => $balance['credit'],
                'balance_cents' => $signed,
            ];

            $groups[$key]['debit_cents'] += $balance['debit'];
            $groups[$key]['credit_cents'] += $balance['credit'];
            $groups[$key]['balance_cents'] += $signed;
        }

        return [
            'range' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'types' => array_values($groups),
            'currency' => 'PHP',
        ];
    }

    /** @return array<string, array{debit: int, credit: int}> */
    public function balances(?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->postings()
            ->when($from !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->select('journal_lines.account_id as account_id')
            ->selectRaw('SUM('.$this->column('journal_lines.debit_cents').') as debit_total')
            ->selectRaw('SUM('.$this->column('journal_lines.credit_cents').') as credit_total')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->account_id => [
                    'debit' => (int) $row->debit_total,
                    'credit' => (int) $row->credit_total,
                ],
            ])
            ->all();
    }

    private function openingBalance(Account $account, ?Carbon $from): int
    {
        if ($from === null || ! $account->type->isPermanent()) {
            return 0;
        }

        $sums = $this->postings()
            ->where('journal_lines.account_id', $account->getKey())
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM('.$this->column('journal_lines.debit_cents').'), 0) as debit_total')
            ->selectRaw('COALESCE(SUM('.$this->column('journal_lines.credit_cents').'), 0) as credit_total')
            ->first();

        $normal = $account->normalBalance();

        return $normal->sign(BalanceSide::Debit) * (int) ($sums->debit_total ?? 0)
            + $normal->sign(BalanceSide::Credit) * (int) ($sums->credit_total ?? 0);
    }

    private function column(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }

    private function postings(): Builder
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::POSTED)
            ->whereNull('journal_entries.deleted_at');
    }

    /** @return Collection<int, Account> */
    public function accounts(): Collection
    {
        return Account::query()->inChartOrder()->get();
    }
}
