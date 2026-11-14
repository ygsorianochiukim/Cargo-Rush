<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Finance\Services\ExpenseLinesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TruckDetailService
{
    public const PERIODS = ['month', 'quarter', 'year'];

    public function __construct(
        private readonly ExpenseLinesService $lines,
        private readonly LedgerRepository $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function overview(Truck $truck, string $period, Carbon $date): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        [$from, $to, $label] = $this->range($period, $date);
        [$prevFrom, $prevTo] = $this->previous($period, $from);

        $entries = $this->entries($truck, $from, $to);
        $costs = $this->costs($truck, $from, $to);
        $before = $this->costs($truck, $prevFrom, $prevTo);

        $income = (int) $entries->sum('trip_income_cents');
        $expenses = (int) $costs->sum('amount_cents');
        $prevIncome = (int) $this->entries($truck, $prevFrom, $prevTo)->sum('trip_income_cents');
        $prevExpenses = (int) $before->sum('amount_cents');

        return [
            'truck' => $this->card($truck),
            'trucks' => $this->ledger->trucks()->map(fn (Truck $t): array => $this->card($t))->values()->all(),
            'period' => $period,
            'label' => $label,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_range' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'kpis' => $this->kpis($income, $expenses, $prevIncome, $prevExpenses),
            'trips' => $entries->count(),
            'customers' => $this->customers($entries),
            'trend' => $this->trend($truck, $to),
            'costs' => $this->costMix($costs, $before),
            'destinations' => $this->destinations($entries),
            'currency' => 'PHP',
        ];
    }

    /** @return array{id: string, label: string, plate: ?string} */
    private function card(Truck $truck): array
    {
        return ['id' => $truck->id, 'label' => $truck->label, 'plate' => $truck->plate];
    }

    /** @return Collection<int, LedgerEntry> */
    private function entries(Truck $truck, Carbon $from, Carbon $to): Collection
    {
        return LedgerEntry::query()
            ->where('truck_id', $truck->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->with(['customer:id,name', 'trip:id,customer_id,destination', 'trip.customer:id,name'])
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function costs(Truck $truck, Carbon $from, Carbon $to): Collection
    {
        return collect($this->lines->between($from, $to)['lines'])
            ->where('truck_id', $truck->id)
            ->values();
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

    /** @return array<int, array<string, mixed>> */
    private function kpis(int $income, int $expenses, int $prevIncome, int $prevExpenses): array
    {
        $tile = static fn (string $key, string $label, int $value, int $previous, bool $higherIsBetter): array => [
            'key' => $key,
            'label' => $label,
            'value_cents' => $value,
            'previous_cents' => $previous,
            'change' => $previous === 0 ? null : ($value - $previous) / abs($previous),
            'higher_is_better' => $higherIsBetter,
        ];

        $margin = $income === 0 ? null : ($income - $expenses) / $income;
        $prevMargin = $prevIncome === 0 ? null : ($prevIncome - $prevExpenses) / $prevIncome;

        return [
            $tile('income', 'Gross income', $income, $prevIncome, true),
            $tile('expenses', 'Total expenses', $expenses, $prevExpenses, false),
            $tile('net', 'Net profit / loss', $income - $expenses, $prevIncome - $prevExpenses, true),
            [
                ...$tile('margin', 'Profit percentage', 0, 0, true),
                'value_cents' => null,
                'previous_cents' => null,
                'ratio' => $margin,
                'previous_ratio' => $prevMargin,
                'change' => $margin === null || $prevMargin === null ? null : $margin - $prevMargin,
            ],
        ];
    }

    /**
     * @param  Collection<int, LedgerEntry>  $entries
     * @return array<int, array{name: string, income_cents: int, trips: int}>
     */
    private function customers(Collection $entries): array
    {
        $rows = $entries
            ->groupBy(static fn (LedgerEntry $e): string => $e->customer?->name ?? $e->trip?->customer?->name ?? 'No customer')
            ->map(static fn (Collection $rows, string $name): array => [
                'name' => $name,
                'income_cents' => (int) $rows->sum('trip_income_cents'),
                'trips' => $rows->count(),
            ])
            ->filter(static fn (array $row): bool => $row['income_cents'] !== 0)
            ->sortByDesc('income_cents')
            ->values();

        $top = $rows->take(3)->all();
        $rest = $rows->slice(3);

        if ($rest->isNotEmpty()) {
            $top[] = [
                'name' => 'Others',
                'income_cents' => (int) $rest->sum('income_cents'),
                'trips' => (int) $rest->sum('trips'),
            ];
        }

        return $top;
    }

    /** @return array<int, array<string, mixed>> */
    private function trend(Truck $truck, Carbon $to): array
    {
        $from = $to->copy()->startOfMonth()->subMonthsNoOverflow(11);
        $end = $to->copy()->endOfMonth()->startOfDay();

        $income = $this->entries($truck, $from, $end)
            ->groupBy(static fn (LedgerEntry $e): string => $e->date->format('Y-m'))
            ->map->sum('trip_income_cents');

        $costs = $this->costs($truck, $from, $end)
            ->groupBy(static fn (array $line): string => Carbon::parse($line['date'])->format('Y-m'))
            ->map->sum('amount_cents');

        $months = [];

        for ($cursor = $from->copy(); $cursor->lte($end); $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');

            $months[] = [
                'key' => $key,
                'label' => $cursor->format('M'),
                'year' => (int) $cursor->year,
                'sales_cents' => (int) ($income[$key] ?? 0),
                'expenses_cents' => (int) ($costs[$key] ?? 0),
            ];
        }

        return $months;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $now
     * @param  Collection<int, array<string, mixed>>  $before
     * @return array<int, array{kind: string, current_cents: int, previous_cents: int, variance_cents: int}>
     */
    private function costMix(Collection $now, Collection $before): array
    {
        $current = $now->groupBy('kind')->map->sum('amount_cents');
        $previous = $before->groupBy('kind')->map->sum('amount_cents');

        return $current->keys()->merge($previous->keys())->unique()
            ->map(static fn (string $kind): array => [
                'kind' => $kind,
                'current_cents' => (int) ($current[$kind] ?? 0),
                'previous_cents' => (int) ($previous[$kind] ?? 0),
                'variance_cents' => (int) ($current[$kind] ?? 0) - (int) ($previous[$kind] ?? 0),
            ])
            ->sortByDesc('current_cents')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, LedgerEntry>  $entries
     * @return array<int, array{name: string, trips: int, income_cents: int}>
     */
    private function destinations(Collection $entries): array
    {
        return $entries
            ->groupBy(static function (LedgerEntry $e): string {
                $place = trim((string) ($e->trip?->destination ?: $e->route));

                return $place === '' ? 'Not recorded' : $place;
            })
            ->map(static fn (Collection $rows, string $name): array => [
                'name' => $name,
                'trips' => $rows->count(),
                'income_cents' => (int) $rows->sum('trip_income_cents'),
            ])
            ->sortBy([['trips', 'desc'], ['income_cents', 'desc']])
            ->take(5)
            ->values()
            ->all();
    }
}
