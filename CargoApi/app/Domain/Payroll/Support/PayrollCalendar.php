<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Carbon;

final class PayrollCalendar
{
    public const MAX_CUTOFFS = 3;

    public const LATEST_FIRST_CUTOFF = 27;

    public const LAST_DAY = 31;

    public const DEFAULT_RELEASE_LAG = 2;

    /** @param array<int, int> $days */
    private function __construct(
        public readonly array $days,
        public readonly int $releaseLagDays = self::DEFAULT_RELEASE_LAG,
    ) {}

    public function releaseFor(Carbon $cutoff): Carbon
    {
        return $cutoff->copy()->addDays($this->releaseLagDays);
    }

    /** @param array<int, mixed> $days */
    public static function of(array $days, ?int $releaseLagDays = null): self
    {
        $normalised = self::normalise($days);

        return $normalised === []
            ? self::default()
            : new self($normalised, $releaseLagDays ?? self::configuredLag());
    }

    public static function default(): self
    {
        $days = self::normalise(self::configuredDays());

        return new self($days === [] ? [15, self::LAST_DAY] : $days, self::configuredLag());
    }

    public static function for(?Company $company): self
    {
        $days = $company?->payroll_cutoff_days;

        $lag = $company?->payroll_release_lag_days ?? self::configuredLag();

        return is_array($days) && $days !== []
            ? self::of($days, $lag)
            : new self(self::default()->days, $lag);
    }

    private static function configuredLag(): int
    {
        return (int) config('cargo.payroll.release_lag_days', self::DEFAULT_RELEASE_LAG);
    }

    public function runsPerMonth(): int
    {
        return count($this->days);
    }

    public function isMonthly(): bool
    {
        return $this->runsPerMonth() === 1;
    }

    /** @return array<int, PayPeriod> */
    public function inMonth(int $year, int $month): array
    {
        $periods = [];

        foreach (array_keys($this->days) as $index) {
            $periods[] = $this->period($year, $month, $index);
        }

        return $periods;
    }

    public function matching(Carbon $start, Carbon $end): ?PayPeriod
    {
        foreach ($this->inMonth((int) $end->year, (int) $end->month) as $period) {
            if ($period->start->isSameDay($start) && $period->end->isSameDay($end)) {
                return $period;
            }
        }

        return null;
    }

    public function justClosed(?Carbon $now = null): PayPeriod
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();
        $cursor = $today->copy()->startOfMonth();

        for ($back = 0; $back <= 2; $back++) {
            foreach (array_reverse($this->inMonth((int) $cursor->year, (int) $cursor->month)) as $period) {
                if ($period->end->lt($today)) {
                    return $period;
                }
            }

            $cursor = $cursor->copy()->subMonthNoOverflow();
        }

        return $this->period((int) $cursor->year, (int) $cursor->month, 0);
    }

    /** @return array{first: bool, only: bool} */
    public function classify(Carbon $start, Carbon $end): array
    {
        $period = $this->matching($start, $end);

        if ($period !== null) {
            return [
                'first' => $period->isFirst(),
                'only' => $period->isOnly(),
                'index' => $period->index,
                'count' => $period->count,
            ];
        }

        $first = (int) $start->day <= $this->days[0];

        return [
            'first' => $first,
            'only' => $this->isMonthly(),
            'index' => $first ? 0 : 1,
            'count' => $this->runsPerMonth(),
        ];
    }

    public function explainFor(Carbon $start): string
    {
        $periods = $this->inMonth((int) $start->year, (int) $start->month);

        if ($this->isMonthly()) {
            return sprintf(
                'Payroll runs once a month here, cut off on %s, so a pay period is %s. Choose that.',
                self::dayLabel($this->days[0]),
                $periods[0]->label(),
            );
        }

        $labels = array_map(static fn (int $day): string => self::dayLabel($day), $this->days);
        $ranges = array_map(static fn (PayPeriod $period): string => $period->label(), $periods);

        return sprintf(
            'Payroll is cut off on %s here, so a pay period runs %s. Choose one of those.',
            self::sentenceList($labels),
            self::sentenceList($ranges, 'or'),
        );
    }

    /** @param array<int, string> $items */
    private static function sentenceList(array $items, string $conjunction = 'and'): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' '.$conjunction.' '.$last;
    }

    public function describe(): string
    {
        if ($this->isMonthly()) {
            return sprintf('Payroll runs once a month, cut off on %s.', self::dayLabel($this->days[0]));
        }

        $labels = array_map(static fn (int $day): string => self::dayLabel($day), $this->days);
        $last = array_pop($labels);

        return sprintf(
            'Payroll runs %s a month, cut off on %s and %s.',
            $this->runsPerMonth() === 2 ? 'twice' : 'three times',
            implode(', ', $labels),
            $last,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(?Carbon $month = null): array
    {
        $month ??= Carbon::now();

        return [
            'cutoff_days' => $this->days,
            'runs_per_month' => $this->runsPerMonth(),
            'description' => $this->describe(),
            'day_labels' => array_map(static fn (int $day): string => self::dayLabel($day), $this->days),
            'release_lag_days' => $this->releaseLagDays,

            'example_periods' => array_map(
                fn (PayPeriod $period): array => [
                    ...$period->toArray(),
                    'release_on' => $this->releaseFor($period->end)->toDateString(),
                ],
                $this->inMonth((int) $month->year, (int) $month->month),
            ),
        ];
    }

    private function period(int $year, int $month, int $index): PayPeriod
    {
        $end = $this->cutoffIn($year, $month, $index);

        $start = $index > 0
            ? $this->cutoffIn($year, $month, $index - 1)->addDay()
            : $this->lastCutoffBefore($year, $month)->addDay();

        if ($start->gt($end)) {
            $start = $end->copy();
        }

        return PayPeriod::between($start, $end, $index, $this->runsPerMonth());
    }

    private function cutoffIn(int $year, int $month, int $index): Carbon
    {
        $first = Carbon::createFromDate($year, $month, 1)->startOfDay();

        return $first->copy()->day(min($this->days[$index], (int) $first->daysInMonth));
    }

    private function lastCutoffBefore(int $year, int $month): Carbon
    {
        $previous = Carbon::createFromDate($year, $month, 1)->startOfDay()->subMonthNoOverflow();

        return $this->cutoffIn((int) $previous->year, (int) $previous->month, $this->runsPerMonth() - 1);
    }

    /**
     * @param  array<int, mixed>  $days
     * @return array<int, int>
     */
    private static function normalise(array $days): array
    {
        $clean = [];

        foreach ($days as $day) {
            if (! is_numeric($day)) {
                continue;
            }

            $day = (int) $day;

            if ($day >= 1 && $day <= self::LAST_DAY) {
                $clean[$day] = $day;
            }
        }

        sort($clean);

        return array_slice($clean, 0, self::MAX_CUTOFFS);
    }

    /** @return array<int, mixed> */
    private static function configuredDays(): array
    {
        $configured = config('cargo.payroll.cutoff_days');

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return max(1, (int) config('cargo.payroll.runs_per_month', 2)) === 1
            ? [self::LAST_DAY]
            : [15, self::LAST_DAY];
    }

    public static function dayLabel(int $day): string
    {
        if ($day >= self::LAST_DAY) {
            return 'the last day of the month';
        }

        $suffix = match (true) {
            in_array($day % 100, [11, 12, 13], true) => 'th',
            $day % 10 === 1 => 'st',
            $day % 10 === 2 => 'nd',
            $day % 10 === 3 => 'rd',
            default => 'th',
        };

        return 'the '.$day.$suffix;
    }
}
