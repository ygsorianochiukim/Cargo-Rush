<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Carbon;

/**
 * When one firm's pay periods begin and end.
 *
 * The calendar payroll is run against, built from the company's own cutoff days
 * (`companies.payroll_cutoff_days`). Everything that used to be a static call
 * on `PayPeriod` reading `config()` is here instead, as an instance, and that
 * is the whole reason the class exists: the cutoff is **per haulier**. An
 * environment variable served one answer to every company on the install, so a
 * firm cutting off on the 10th and the 25th could not be answered at all and
 * moving one firm to a monthly payroll moved all of them.
 *
 * ## What a cutoff day is
 *
 * The **last day worked** in a period, which is what an office means by the
 * word. One or two of them, ascending. `[15, 31]` is the Philippine norm and
 * produces the 1st-15th and the 16th-end; `[10, 25]` produces the 26th-10th and
 * the 11th-25th; `[31]` is a monthly payroll.
 *
 * A day past the end of a short month clamps to the last one, so `31` is how a
 * firm says "the end of the month" and February takes care of itself.
 *
 * ## A period belongs to the month its cutoff falls in
 *
 * Not the month it starts in, and the distinction only becomes visible once
 * cutoffs are free: under `[10, 25]` the first period of September starts on
 * the 26th of August. It is September's because that is when it closes, when it
 * is paid, and when it is filed. Under `[15, 31]` the two readings agree, which
 * is why nothing about an existing install changes.
 *
 * ## One or two, and not more
 *
 * The statutory arithmetic downstream is semi-monthly. `DeductionSchedule`
 * splits a monthly contribution across at most two payslips, and the
 * withholding table in `config/cargo.php` is the BIR's **semi-monthly** one. A
 * weekly payroll is not a longer list here - it is a different tax table - so a
 * third cutoff is dropped rather than accepted and quietly taxed on a table
 * built for a fortnight.
 */
final class PayrollCalendar
{
    /**
     * How many times a month a firm may cut off.
     *
     * Three. Two was the limit while the arithmetic downstream only knew how
     * to halve a month — see `MonthlyShare`, which is what replaced the halving
     * and made a third run safe to pay.
     *
     * The **withholding table has not caught up**. `config/cargo.php` holds the
     * BIR's semi-monthly brackets, which describe 24 periods a year; a firm on
     * three cutoffs has 36, and running that table on each of them over-states
     * the tax on every payslip. The screens say so where somebody can act on
     * it. Raising this to four would need the same conversation again.
     */
    public const MAX_CUTOFFS = 3;

    /**
     * The latest any cutoff but the last may fall.
     *
     * The 27th, and the reason is February. A cutoff clamps to the last day of
     * a short month, so an earlier cutoff on the 28th would collide with a
     * later one in a 28-day February - leaving the second period starting on
     * the 1st of March and ending on the 28th of February, which is not a
     * period. At the 27th or below there is always at least one day left for
     * the second, in every month there is.
     */
    public const LATEST_FIRST_CUTOFF = 27;

    /** The day that means "the end of the month", whatever length it is. */
    public const LAST_DAY = 31;

    /**
     * Days between a cutoff and the release, where nothing says otherwise.
     *
     * Two, which is the gap the office needs to compile the period's charges
     * and get the budget released. Overridden per install in `config/cargo.php`
     * and per firm on the company.
     */
    public const DEFAULT_RELEASE_LAG = 2;

    /**
     * @param  array<int, int>  $days  Ascending, distinct, 1-31, one or two of
     *                                 them. Guaranteed by the constructors.
     */
    private function __construct(
        public readonly array $days,
        /**
         * Days between a cutoff and the money going out.
         *
         * Part of the calendar rather than a separate lookup, because "when is
         * this period paid" is the same question as "when does it close" asked
         * two days later, and a screen showing one wants the other.
         */
        public readonly int $releaseLagDays = self::DEFAULT_RELEASE_LAG,
    ) {}

    /**
     * The day a period closing on this date is paid.
     *
     * Calendar days, not working days. The firm this was built for releases on
     * the 7th, the 17th and the 27th whatever day of the week those fall on,
     * and a working-day rule would need a holiday calendar this system does not
     * have — one invented here would be wrong every Holy Week.
     */
    public function releaseFor(Carbon $cutoff): Carbon
    {
        return $cutoff->copy()->addDays($this->releaseLagDays);
    }

    /**
     * A calendar from a list of cutoff days.
     *
     * Forgiving rather than strict, deliberately: this is what reads a column
     * back, and a payslip is the wrong place to discover that somebody wrote
     * something odd into the database by hand. Anything unusable is dropped and
     * the firm falls back to the configured default. The *validation* - what a
     * company may set in the first place - is `PayrollCutoffDays`, which
     * refuses rather than repairs, because that is a form and a form should say
     * no.
     *
     * @param  array<int, mixed>  $days
     */
    public static function of(array $days, ?int $releaseLagDays = null): self
    {
        $normalised = self::normalise($days);

        return $normalised === []
            ? self::default()
            : new self($normalised, $releaseLagDays ?? self::configuredLag());
    }

    /**
     * The install-wide default, for a company that has never set its own.
     *
     * Derived from `cargo.payroll.runs_per_month` where no explicit
     * `cutoff_days` is configured, so an install running
     * `PAYROLL_RUNS_PER_MONTH=1` keeps the monthly calendar it has always had
     * and nobody's payroll changes shape on deploy.
     */
    public static function default(): self
    {
        $days = self::normalise(self::configuredDays());

        return new self($days === [] ? [15, self::LAST_DAY] : $days, self::configuredLag());
    }

    /** The calendar a given company runs on. Null falls back to the default. */
    public static function for(?Company $company): self
    {
        $days = $company?->payroll_cutoff_days;

        // `??` rather than `?:` — zero is a firm paying on the cutoff itself,
        // and only null means "use the install default".
        $lag = $company?->payroll_release_lag_days ?? self::configuredLag();

        return is_array($days) && $days !== []
            ? self::of($days, $lag)
            : new self(self::default()->days, $lag);
    }

    /** The install's release lag, for a firm that has not set its own. */
    private static function configuredLag(): int
    {
        return (int) config('cargo.payroll.release_lag_days', self::DEFAULT_RELEASE_LAG);
    }

    /** How many payslips a month - one, two or three. */
    public function runsPerMonth(): int
    {
        return count($this->days);
    }

    /** Does this firm pay once a month? */
    public function isMonthly(): bool
    {
        return $this->runsPerMonth() === 1;
    }

    /**
     * Every period whose cutoff falls in this month, in the order they close.
     *
     * @return array<int, PayPeriod>
     */
    public function inMonth(int $year, int $month): array
    {
        $periods = [];

        foreach (array_keys($this->days) as $index) {
            $periods[] = $this->period($year, $month, $index);
        }

        return $periods;
    }

    /**
     * The period these two dates are, or null if they are not one.
     *
     * Looked up in the month of the **end** date, because that is the month a
     * period belongs to - see the class note. Under a 1st/16th calendar the two
     * dates share a month and this is the same lookup it always was.
     */
    public function matching(Carbon $start, Carbon $end): ?PayPeriod
    {
        foreach ($this->inMonth((int) $end->year, (int) $end->month) as $period) {
            if ($period->start->isSameDay($start) && $period->end->isSameDay($end)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * The period that has just closed - the one an office opens payroll for.
     *
     * Whatever the last cutoff to have passed was: on the 16th under a 1st/16th
     * calendar, the first half of this month; before that, the back half of
     * last month. Read off the calendar by walking periods backwards rather
     * than written as a rule about the 16th, so it stays true for a firm
     * cutting off on the 10th and the 25th.
     */
    public function justClosed(?Carbon $now = null): PayPeriod
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();
        $cursor = $today->copy()->startOfMonth();

        // Two months back is more than enough - every month has at least one
        // period, so the previous month's last one has always closed.
        for ($back = 0; $back <= 2; $back++) {
            foreach (array_reverse($this->inMonth((int) $cursor->year, (int) $cursor->month)) as $period) {
                if ($period->end->lt($today)) {
                    return $period;
                }
            }

            $cursor = $cursor->copy()->subMonthNoOverflow();
        }

        // Unreachable. Stated rather than left to fall off the end of the
        // function, because a null here would surface as a mangled screen.
        return $this->period((int) $cursor->year, (int) $cursor->month, 0);
    }

    /**
     * Which payslip a run's dates are, for splitting a monthly figure.
     *
     * `first` decides which share of the salary and of the contributions this
     * payslip carries; `only` says there is no second payslip for the rest to
     * land on, which is the monthly-payroll case.
     *
     * A run whose dates are not a period on **this** calendar - one opened
     * before the firm moved its cutoffs, or before the rule existed at all - is
     * classified by its start day rather than refused. This is arithmetic on a
     * run that already exists, and the honest answer for a period starting
     * before the first cutoff is "the first payslip of the month". Refusing
     * would make every historical run unreadable the day a firm changed its
     * calendar, which is the opposite of what freezing a payslip is for.
     *
     * @return array{first: bool, only: bool}
     */
    public function classify(Carbon $start, Carbon $end): array
    {
        $period = $this->matching($start, $end);

        if ($period !== null) {
            return [
                'first' => $period->isFirst(),
                'only' => $period->isOnly(),
                // Which run of how many, which is what the money actually needs:
                // a monthly salary and a monthly contribution are both cut into
                // `count` pieces, and two booleans cannot say "the second of
                // three". See `MonthlyShare`.
                'index' => $period->index,
                'count' => $period->count,
            ];
        }

        // A range that is not one of this firm's periods at all. Guessed from
        // the first cutoff, as it always was, and reported as a run of the
        // right size so nothing downstream divides by a count it cannot trust.
        $first = (int) $start->day <= $this->days[0];

        return [
            'first' => $first,
            'only' => $this->isMonthly(),
            'index' => $first ? 0 : 1,
            'count' => $this->runsPerMonth(),
        ];
    }

    /**
     * Why a range was refused, naming the periods it should have been.
     *
     * The message does the teaching, because the reader is somebody who typed a
     * sensible-looking fortnight and got a 422: it has to say what this firm's
     * rule is and what the right answers are for the month they were aiming at.
     * The cutoff days are named, not assumed, since they are now the office's
     * own setting and the office may have forgotten changing them.
     */
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

        // Every cutoff and every period it makes, however many there are — the
        // reader is somebody who typed a sensible-looking range and got a 422,
        // and a message naming two of their three periods would send them
        // straight back into the same mistake.
        $labels = array_map(static fn (int $day): string => self::dayLabel($day), $this->days);
        $ranges = array_map(static fn (PayPeriod $period): string => $period->label(), $periods);

        return sprintf(
            'Payroll is cut off on %s here, so a pay period runs %s. Choose one of those.',
            self::sentenceList($labels),
            self::sentenceList($ranges, 'or'),
        );
    }

    /**
     * "a, b and c" — the join an English sentence wants.
     *
     * Here rather than inline because two messages need it and both used to
     * hardcode exactly two items, which is how a three-cutoff firm was told
     * about two of its cutoffs.
     *
     * @param  array<int, string>  $items
     */
    private static function sentenceList(array $items, string $conjunction = 'and'): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' '.$conjunction.' '.$last;
    }

    /** One sentence for the settings screen, in the office's own numbers. */
    public function describe(): string
    {
        if ($this->isMonthly()) {
            return sprintf('Payroll runs once a month, cut off on %s.', self::dayLabel($this->days[0]));
        }

        /**
         * Built from the list rather than written per case.
         *
         * There were two sentences here, one for a monthly payroll and one that
         * said "twice" and named exactly two days — so a firm on three cutoffs
         * would have been told it runs twice a month and shown two of its three
         * dates. A screen that describes a setting has to describe the setting
         * that is actually there.
         */
        $labels = array_map(static fn (int $day): string => self::dayLabel($day), $this->days);
        $last = array_pop($labels);

        return sprintf(
            'Payroll runs %s a month, cut off on %s and %s.',
            $this->runsPerMonth() === 2 ? 'twice' : 'three times',
            implode(', ', $labels),
            $last,
        );
    }

    /**
     * The calendar as the clients read it.
     *
     * The periods of a month ride along with the days, so a settings screen can
     * show what the numbers actually mean - "the 10th and the 25th" is not
     * something an office can picture, and "26 Aug-10 Sep, 11-25 Sep" is.
     *
     * @return array<string, mixed>
     */
    public function toArray(?Carbon $month = null): array
    {
        $month ??= Carbon::now();

        return [
            'cutoff_days' => $this->days,
            'runs_per_month' => $this->runsPerMonth(),
            'description' => $this->describe(),
            'day_labels' => array_map(static fn (int $day): string => self::dayLabel($day), $this->days),
            'release_lag_days' => $this->releaseLagDays,

            /**
             * Each period, with the day it is actually paid.
             *
             * The release is what an office plans around — "the 7th, the 17th
             * and the 27th" is the thing people say to each other — and it is
             * not the cutoff. Sent with the period rather than left to the
             * client to add two days to a date, which is how the two screens
             * end up disagreeing about a Sunday.
             */
            'example_periods' => array_map(
                fn (PayPeriod $period): array => [
                    ...$period->toArray(),
                    'release_on' => $this->releaseFor($period->end)->toDateString(),
                ],
                $this->inMonth((int) $month->year, (int) $month->month),
            ),
        ];
    }

    /**
     * One period of one month.
     *
     * A period runs from the day after the previous cutoff to its own. The
     * previous cutoff for the month's first period is the **last** cutoff of
     * the month before, which is what lets a period cross a month boundary
     * without any special case for it.
     */
    private function period(int $year, int $month, int $index): PayPeriod
    {
        $end = $this->cutoffIn($year, $month, $index);

        $start = $index > 0
            ? $this->cutoffIn($year, $month, $index - 1)->addDay()
            : $this->lastCutoffBefore($year, $month)->addDay();

        // Defensive, and it should never fire: `PayrollCutoffDays` keeps the
        // earlier cutoff at the 27th or below precisely so two clamped cutoffs
        // cannot land on the same day in February. A row written past that
        // validation gets a one-day period rather than an inverted one, because
        // a period whose start is after its end would produce a negative
        // fortnight on a payslip.
        if ($start->gt($end)) {
            $start = $end->copy();
        }

        return PayPeriod::between($start, $end, $index, $this->runsPerMonth());
    }

    /** The nth cutoff of a month, clamped to the days that month has. */
    private function cutoffIn(int $year, int $month, int $index): Carbon
    {
        $first = Carbon::createFromDate($year, $month, 1)->startOfDay();

        return $first->copy()->day(min($this->days[$index], (int) $first->daysInMonth));
    }

    /** The last cutoff of the month before this one. */
    private function lastCutoffBefore(int $year, int $month): Carbon
    {
        $previous = Carbon::createFromDate($year, $month, 1)->startOfDay()->subMonthNoOverflow();

        return $this->cutoffIn((int) $previous->year, (int) $previous->month, $this->runsPerMonth() - 1);
    }

    /**
     * Ascending, distinct, in range, and at most two.
     *
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

    /**
     * The configured default, as days.
     *
     * `cargo.payroll.cutoff_days` where an install has set one; otherwise built
     * from `runs_per_month`, which is the setting that existed before this and
     * which several tests still reach for.
     *
     * @return array<int, mixed>
     */
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

    /**
     * `the 15th`, or `the last day of the month` for the 31st.
     *
     * The 31st is how a firm says "the end of the month" - see the class note -
     * so reading it back as "the 31st" to an office whose September has thirty
     * days would be the one number on the screen that was wrong.
     */
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
