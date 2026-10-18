<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TruckReportService
{
    public const PERIODS = ['day', 'cutoff', 'month'];

    public function __construct(
        private readonly FinanceService $finance,
        private readonly ExpenseLinesService $lines,
        private readonly LedgerRepository $ledger,
        private readonly Tenant $tenant,
    ) {}

    /** @return array<string, mixed> */
    public function report(string $period, Carbon $date): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        [$from, $to, $label] = $this->range($period, $date->copy()->startOfDay());

        $pnl = collect($this->finance->pnlByTruck($from, $to))->keyBy('truck.id');
        $lines = collect($this->lines->between($from, $to)['lines']);
        $known = $this->ledger->trucks()->pluck('id')->flip();

        $grouped = $lines->groupBy(static fn (array $line): string => $line['truck_id'] !== null && $known->has($line['truck_id'])
            ? $line['truck_id']
            : '');

        $trucks = $this->ledger->trucks()->map(function ($truck) use ($pnl, $grouped): array {
            $mine = $grouped->get($truck->id, collect())->values();
            $income = (int) ($pnl->get($truck->id)['trip_income_cents'] ?? 0);
            $expenses = (int) $mine->sum('amount_cents');

            return [
                'truck' => ['id' => $truck->id, 'label' => $truck->label, 'plate' => $truck->plate],
                'income_cents' => $income,
                'expenses_cents' => $expenses,
                'net_income_cents' => $income - $expenses,
                'by_kind' => $this->byKind($mine),
                'lines' => $mine->all(),
            ];
        })->values();

        $unassigned = $grouped->get('', collect())->values();
        $unassignedCents = (int) $unassigned->sum('amount_cents');

        $income = (int) $trucks->sum('income_cents');
        $truckExpenses = (int) $trucks->sum('expenses_cents');

        return [
            'period' => $period,
            'label' => $label,
            'date' => $date->toDateString(),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous' => $from->copy()->subDay()->toDateString(),
            'next' => $to->copy()->addDay()->toDateString(),
            'trucks' => $trucks->all(),
            'unassigned' => [
                'expenses_cents' => $unassignedCents,
                'by_kind' => $this->byKind($unassigned),
                'lines' => $unassigned->all(),
            ],
            'totals' => [
                'income_cents' => $income,
                'truck_expenses_cents' => $truckExpenses,
                'truck_net_cents' => $income - $truckExpenses,
                'unassigned_cents' => $unassignedCents,
                'expenses_cents' => $truckExpenses + $unassignedCents,
                'net_income_cents' => $income - $truckExpenses - $unassignedCents,
            ],
            'currency' => 'PHP',
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function range(string $period, Carbon $date): array
    {
        if ($period === 'day') {
            return [$date->copy(), $date->copy(), $date->format('j M Y')];
        }

        if ($period === 'month') {
            return [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()->startOfDay(), $date->format('F Y')];
        }

        $cutoff = $this->cutoffContaining($date);

        return [$cutoff->start->copy()->startOfDay(), $cutoff->end->copy()->startOfDay(), $cutoff->label()];
    }

    private function cutoffContaining(Carbon $date): PayPeriod
    {
        $calendar = PayrollCalendar::for($this->tenant->company());

        foreach ([$date->copy()->startOfMonth(), $date->copy()->startOfMonth()->addMonthNoOverflow()] as $month) {
            foreach ($calendar->inMonth((int) $month->year, (int) $month->month) as $period) {
                if ($date->betweenIncluded($period->start->copy()->startOfDay(), $period->end->copy()->endOfDay())) {
                    return $period;
                }
            }
        }

        return $calendar->inMonth((int) $date->year, (int) $date->month)[0];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<int, array{kind: string, amount_cents: int, count: int}>
     */
    private function byKind($lines): array
    {
        return $lines->groupBy('kind')
            ->map(static fn ($rows, $kind): array => [
                'kind' => (string) $kind,
                'amount_cents' => (int) $rows->sum('amount_cents'),
                'count' => $rows->count(),
            ])
            ->sortByDesc('amount_cents')
            ->values()
            ->all();
    }
}
