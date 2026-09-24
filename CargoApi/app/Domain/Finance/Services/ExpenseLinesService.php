<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The transactions behind a period's Total expenses, one row each.
 *
 * `FinanceService::periodTotals()` adds four sources into one figure: the
 * daily sheet's cost columns, the categorised expense lines, the supplier bills
 * paid, and the payouts handed to partners. A tile showing that figure has to
 * be able to say what it is made of, and the list has to add up to it to the
 * centavo — a breakdown that is ₱200 off the number it breaks down is worse
 * than none. So every row here comes from the same query the roll-up sums, with
 * the same exclusions, and the test pins the two together.
 *
 * A sheet day is split into one row per column it has a cost in. "Truck 1, 14
 * July, ₱18,400" is not a transaction anybody recognises; "Fuel, Truck 1, 14
 * July, ₱6,200" is.
 */
class ExpenseLinesService
{
    /** The sheet's cost columns, in the order the workbook has them. */
    private const COLUMNS = [
        'fuel_cents' => 'Fuel',
        'driver_salary_cents' => 'Driver salary',
        'helper_salary_cents' => 'Helper salary',
        'maintenance_cents' => 'Maintenance',
        'allowance_cents' => 'Allowance',
        'owner_share_cents' => "Owner's share",
    ];

    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly ExpenseRepository $expenses,
        private readonly InvoiceRepository $invoices,
        private readonly WalletService $wallet,
    ) {}

    /**
     * @return array{range: array{from: string, to: string}, lines: array<int, array<string, mixed>>, total_cents: int, currency: string}
     */
    public function between(Carbon $from, Carbon $to): array
    {
        $trucks = $this->ledger->trucks()->keyBy('id');

        $lines = [
            ...$this->sheetLines($from, $to, $trucks),
            ...$this->expenseLines($from, $to, $trucks),
            ...$this->supplierBillLines($from, $to),
            ...$this->payoutLines($from, $to),
        ];

        // Newest first, like every other list of money in the app. The key
        // breaks ties so the same window always reads in the same order.
        usort($lines, static fn (array $a, array $b): int => [$b['date'], $a['key']] <=> [$a['date'], $b['key']]);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'lines' => $lines,
            'total_cents' => (int) array_sum(array_column($lines, 'amount_cents')),
            'currency' => 'PHP',
        ];
    }

    /**
     * The daily sheet, a row per cost column.
     *
     * Only days on a truck the fleet still lists — the roll-up is built truck
     * by truck, so a day on any other would be in this list and not the total.
     */
    private function sheetLines(Carbon $from, Carbon $to, Collection $trucks): array
    {
        $lines = [];

        $entries = $this->ledger->entriesBetween($from, $to)
            ->filter(static fn (LedgerEntry $entry): bool => $trucks->has($entry->truck_id))
            ->load('helpers.driver:id,name');

        foreach ($entries as $entry) {
            $truck = $trucks->get($entry->truck_id);

            foreach (self::COLUMNS as $column => $label) {
                $cents = (int) $entry->{$column};

                if ($cents === 0) {
                    continue;
                }

                // Helper pay is one row per helper, named, rather than the
                // day's total — two helpers are two people paid two amounts.
                // The lines sum to the column, so the list still adds up.
                if ($column === 'helper_salary_cents' && $entry->helpers->isNotEmpty()) {
                    foreach ($entry->helpers as $line) {
                        if ($line->salary_cents === 0) {
                            continue;
                        }

                        $lines[] = $this->line(
                            key: "sheet:{$entry->id}:helper:{$line->id}",
                            source: 'sheet',
                            date: $entry->date->toDateString(),
                            kind: $label,
                            description: $line->driver?->name ?? $entry->route ?? $entry->remarks,
                            truck: $truck->plate ?? $truck->label,
                            cents: (int) $line->salary_cents,
                            recordId: $entry->id,
                        );
                    }

                    continue;
                }

                $lines[] = $this->line(
                    key: "sheet:{$entry->id}:{$column}",
                    source: 'sheet',
                    date: $entry->date->toDateString(),
                    kind: $label,
                    description: $entry->route ?? $entry->remarks,
                    truck: $truck->plate ?? $truck->label,
                    cents: $cents,
                    recordId: $entry->id,
                );
            }
        }

        return $lines;
    }

    /** The categorised lines: a truck's, and the overhead that is no truck's. */
    private function expenseLines(Carbon $from, Carbon $to, Collection $trucks): array
    {
        return $this->expenses->between($from, $to)
            ->filter(static fn (Expense $e): bool => $e->truck_id === null || $trucks->has($e->truck_id))
            ->load(['category:id,name', 'supplier:id,name'])
            ->map(function (Expense $expense) use ($trucks): array {
                $truck = $expense->truck_id === null ? null : $trucks->get($expense->truck_id);

                return $this->line(
                    key: "expense:{$expense->id}",
                    source: 'expense',
                    date: $expense->date->toDateString(),
                    kind: $expense->category?->name ?? 'Expense',
                    description: $expense->supplier?->name ?? $expense->payee ?? $expense->note,
                    truck: $truck === null ? null : ($truck->plate ?? $truck->label),
                    cents: (int) $expense->amount_cents,
                    recordId: $expense->id,
                );
            })
            ->values()
            ->all();
    }

    /** Supplier bills, at what each payment put against them, on the day it was paid. */
    private function supplierBillLines(Carbon $from, Carbon $to): array
    {
        return $this->invoices->settlementsBetween(InvoiceDirection::Payable, $from, $to)
            ->load('invoice')
            ->map(fn (PaymentAllocation $allocation): array => $this->line(
                key: "bill:{$allocation->id}",
                source: 'supplier_bill',
                date: $allocation->payment->paid_on->toDateString(),
                kind: 'Supplier bill',
                description: trim(($allocation->invoice?->number ?? '').' · '.($allocation->invoice?->counterparty() ?? ''), ' ·'),
                truck: null,
                cents: (int) $allocation->amount_cents,
                recordId: $allocation->invoice_id,
            ))
            ->values()
            ->all();
    }

    /** What partners were handed. Stored negative, as money leaving; shown as a cost. */
    private function payoutLines(Carbon $from, Carbon $to): array
    {
        return $this->wallet->payoutsLandedBetween($from, $to)
            ->load(['trucker:id,name', 'trip:id,reference'])
            ->map(fn (WalletEntry $payout): array => $this->line(
                key: "payout:{$payout->id}",
                source: 'trucker_payout',
                date: $payout->occurred_on->toDateString(),
                kind: 'Trucker payout',
                description: trim(($payout->trucker?->name ?? '').' · '.$payout->describe(), ' ·'),
                truck: null,
                cents: abs((int) $payout->amount_cents),
                recordId: $payout->trucker_id,
            ))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function line(
        string $key,
        string $source,
        string $date,
        string $kind,
        ?string $description,
        ?string $truck,
        int $cents,
        ?string $recordId,
    ): array {
        return [
            'key' => $key,
            'source' => $source,
            'date' => $date,
            'kind' => $kind,
            'description' => $description === '' ? null : $description,
            'truck' => $truck,
            'amount_cents' => $cents,
            // The record a row opens: the sheet day, the expense, the bill, or
            // the partner. Which screen that is depends on `source`.
            'record_id' => $recordId,
        ];
    }
}
