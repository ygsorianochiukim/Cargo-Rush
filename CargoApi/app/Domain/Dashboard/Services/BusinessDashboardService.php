<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\ExpenseLinesService;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Finance\Services\PayablesService;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Services\WalletService;
use Illuminate\Support\Carbon;

class BusinessDashboardService
{
    public const PERIODS = ['month', 'quarter', 'year'];

    public function __construct(
        private readonly FinanceService $finance,
        private readonly ExpenseLinesService $lines,
        private readonly PayablesService $payables,
        private readonly BillingService $billing,
        private readonly InvoiceRepository $invoices,
        private readonly WalletService $wallet,
    ) {}

    /** @return array<string, mixed> */
    public function overview(string $period, Carbon $date): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        [$from, $to, $label] = $this->range($period, $date);
        [$prevFrom, $prevTo] = $this->previous($period, $from);

        $now = $this->finance->periodRollup($from, $to)['totals'];
        $before = $this->finance->periodRollup($prevFrom, $prevTo)['totals'];

        return [
            'period' => $period,
            'label' => $label,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_range' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'kpis' => $this->kpis($now, $before),
            'income' => [
                'trip_income_cents' => (int) $now['trip_income_cents'],
                'trucker_commission_cents' => (int) $now['trucker_commission_cents'],
                'other_income_cents' => (int) $now['other_income_cents'],
                'vat_collected_cents' => (int) $now['vat_collected_cents'],
            ],
            'receivables' => $this->receivables($from, $to),
            'payables' => $this->payables($from, $to),
            'trend' => $this->trend($to),
            'expense_mix' => $this->expenseMix($from, $to),
            'top_customers' => $this->topCustomers($from, $to),
            'paid_from' => $this->paidFrom($from, $to),
            'currency' => 'PHP',
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function range(string $period, Carbon $date): array
    {
        return match ($period) {
            'quarter' => [
                $date->copy()->firstOfQuarter()->startOfDay(),
                $date->copy()->lastOfQuarter()->startOfDay(),
                'Q'.$date->quarter.' '.$date->year,
            ],
            'year' => [
                $date->copy()->startOfYear(),
                $date->copy()->endOfYear()->startOfDay(),
                (string) $date->year,
            ],
            default => [
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth()->startOfDay(),
                $date->format('F Y'),
            ],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function previous(string $period, Carbon $from): array
    {
        $start = match ($period) {
            'quarter' => $from->copy()->subQuarterNoOverflow(),
            'year' => $from->copy()->subYearNoOverflow(),
            default => $from->copy()->subMonthNoOverflow(),
        };

        return [$start, $this->range($period, $start)[1]];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $before
     * @return array<int, array<string, mixed>>
     */
    private function kpis(array $now, array $before): array
    {
        $tile = static fn (string $key, string $label, int $value, int $previous, bool $higherIsBetter): array => [
            'key' => $key,
            'label' => $label,
            'value_cents' => $value,
            'previous_cents' => $previous,
            'change' => $previous === 0 ? null : ($value - $previous) / abs($previous),
            'higher_is_better' => $higherIsBetter,
        ];

        return [
            $tile('income', 'Total income', (int) $now['total_income_cents'], (int) $before['total_income_cents'], true),
            $tile('expenses', 'Total expenses', (int) $now['total_expenses_cents'], (int) $before['total_expenses_cents'], false),
            $tile('net', 'Net income', (int) $now['net_income_cents'], (int) $before['net_income_cents'], true),
            [
                ...$tile('margin', 'Net margin', 0, 0, true),
                'value_cents' => null,
                'previous_cents' => null,
                'ratio' => $now['margin'],
                'previous_ratio' => $before['margin'],
                'change' => $now['margin'] === null || $before['margin'] === null ? null : $now['margin'] - $before['margin'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function receivables(Carbon $from, Carbon $to): array
    {
        $aging = $this->billing->aging(InvoiceDirection::Receivable);
        $collection = $this->invoices->collectionBetween($from, $to);

        return [
            'outstanding_cents' => $aging['total_cents'],
            'overdue_cents' => $aging['total_cents'] - $aging['buckets']['current'],
            'collected_cents' => $this->invoices->settledBetween(InvoiceDirection::Receivable, $from, $to),
            'billed_cents' => $collection['billed_cents'],
            'collection_rate' => $collection['billed_cents'] === 0
                ? null
                : $collection['collected_cents'] / $collection['billed_cents'],
            'aging' => $this->buckets($aging['buckets']),
            'top_debtors' => array_map(static fn (array $row): array => [
                'name' => $row['counterparty'],
                'total_cents' => $row['total_cents'],
                'overdue_cents' => $row['total_cents'] - $row['current'],
            ], array_slice($aging['by_counterparty'], 0, 5)),
        ];
    }

    /** @return array<string, mixed> */
    private function payables(Carbon $from, Carbon $to): array
    {
        $overview = $this->payables->overview();
        $bills = $this->billing->aging(InvoiceDirection::Payable);

        return [
            'outstanding_cents' => (int) $overview['total_cents'],
            'in_flight_cents' => (int) $overview['in_flight_cents'],
            'overdue_count' => (int) array_sum(array_column($overview['groups'], 'overdue_count')),
            'paid_cents' => $this->invoices->settledBetween(InvoiceDirection::Payable, $from, $to)
                + $this->wallet->paidOutBetween($from, $to),
            'groups' => array_values(array_map(static fn (array $group): array => [
                'key' => $group['key'],
                'label' => $group['label'],
                'icon' => $group['icon'],
                'count' => $group['count'],
                'total_cents' => $group['total_cents'],
                'overdue_count' => $group['overdue_count'],
            ], $overview['groups'])),
            'bill_aging' => $this->buckets($bills['buckets']),
        ];
    }

    /**
     * @param  array<string, int>  $buckets
     * @return array<int, array{key: string, label: string, cents: int}>
     */
    private function buckets(array $buckets): array
    {
        $labels = [
            'current' => 'Not yet due',
            '1_30' => '1–30 days late',
            '31_60' => '31–60 days late',
            '61_90' => '61–90 days late',
            'over_90' => 'Over 90 days late',
        ];

        return array_map(
            static fn (string $key): array => ['key' => $key, 'label' => $labels[$key], 'cents' => (int) ($buckets[$key] ?? 0)],
            array_keys($labels),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function trend(Carbon $to): array
    {
        $from = $to->copy()->startOfMonth()->subMonthsNoOverflow(11);
        $sales = $this->finance->sales('monthly', $from, $to->copy()->endOfMonth());
        $byKey = collect($sales['series'])->keyBy('key');

        $months = [];

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $row = $byKey->get($key);

            $months[] = [
                'key' => $key,
                'label' => $cursor->format('M'),
                'year' => (int) $cursor->year,
                'sales_cents' => (int) ($row['sales_cents'] ?? 0),
                'expenses_cents' => (int) ($row['expenses_cents'] ?? 0),
            ];
        }

        return $months;
    }

    /** @return array<int, array{kind: string, amount_cents: int}> */
    private function expenseMix(Carbon $from, Carbon $to): array
    {
        $rows = collect($this->lines->between($from, $to)['lines'])
            ->groupBy('kind')
            ->map(static fn ($lines, $kind): array => ['kind' => (string) $kind, 'amount_cents' => (int) $lines->sum('amount_cents')])
            ->sortByDesc('amount_cents')
            ->values();

        $top = $rows->take(6)->all();
        $rest = (int) $rows->slice(6)->sum('amount_cents');

        if ($rest > 0) {
            $top[] = ['kind' => 'Everything else', 'amount_cents' => $rest];
        }

        return $top;
    }

    /** @return array<int, array{name: string, billed_cents: int, invoices: int}> */
    private function topCustomers(Carbon $from, Carbon $to): array
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->where('direction', InvoiceDirection::Receivable->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->get()
            ->groupBy(static fn (Invoice $invoice): string => $invoice->counterparty())
            ->map(static fn ($invoices, $name): array => [
                'name' => (string) $name,
                'billed_cents' => (int) $invoices->sum(static fn (Invoice $invoice): int => $invoice->dueCents()),
                'invoices' => $invoices->count(),
            ])
            ->sortByDesc('billed_cents')
            ->take(5)
            ->values()
            ->all();
    }

    /** @return array<int, array{name: string, kind: string, amount_cents: int}> */
    private function paidFrom(Carbon $from, Carbon $to): array
    {
        $expenses = Expense::query()
            ->counted()
            ->whereNotNull('payment_source_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['payment_source_id', 'amount_cents'])
            ->groupBy('payment_source_id')
            ->map(static fn ($rows): int => (int) $rows->sum('amount_cents'));

        $fuel = FuelRecord::query()
            ->where('status', StatusValue::Active->value)
            ->whereNotNull('payment_source_id')
            ->whereDate('logged_at', '>=', $from->toDateString())
            ->whereDate('logged_at', '<=', $to->toDateString())
            ->get(['payment_source_id', 'amount_cents'])
            ->groupBy('payment_source_id')
            ->map(static fn ($rows): int => (int) $rows->sum('amount_cents'));

        return PaymentSource::withTrashed()
            ->whereIn('id', $expenses->keys()->merge($fuel->keys())->unique()->all())
            ->get()
            ->map(static fn (PaymentSource $source): array => [
                'name' => $source->name,
                'kind' => $source->kind->label(),
                'amount_cents' => ($expenses[$source->id] ?? 0) + ($fuel[$source->id] ?? 0),
            ])
            ->sortByDesc('amount_cents')
            ->values()
            ->all();
    }
}
