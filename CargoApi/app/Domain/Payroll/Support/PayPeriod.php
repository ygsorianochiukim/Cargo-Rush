<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use Illuminate\Support\Carbon;

/**
 * One pay period: a stretch of days ending on a cutoff.
 *
 * A value object and nothing more. It knows the two dates it spans, which of
 * the month's periods it is, and how many there are — it does **not** know how
 * a firm's calendar is shaped, which month contains which period, or what to do
 * with a range somebody typed. All of that is `PayrollCalendar`, and the split
 * is the point: the calendar differs per company, and a period handed around
 * afterwards must not be able to disagree with the one that made it.
 *
 * ## Why a period is not an arbitrary range
 *
 * Statutory contributions are monthly figures split across the month's runs
 * (see `StatutoryDeductions`), and the withholding table is the BIR's
 * **semi-monthly** one. Both are only correct if a run really is one of the
 * firm's periods. A run covering the 3rd to the 20th under a 1st/16th calendar
 * would take half a month's SSS off eighteen days of pay and tax it on a table
 * built for fifteen — wrong twice, and wrong invisibly. So a run is opened on a
 * period the calendar produced, never on two dates a form collected.
 *
 * ## What it costs
 *
 * A period that is not one of the firm's cannot be opened at all: no 13th-month
 * run, no final-pay run for somebody leaving mid-period, no one-off. Those are
 * real things an office eventually needs, and the way to do them here is an
 * adjustment on the next run's payslip, or a journal entry. That is a
 * deliberate trade for figures that are right by construction.
 */
final class PayPeriod
{
    public const FIRST_HALF = 'first';

    public const SECOND_HALF = 'second';

    public const WHOLE_MONTH = 'month';

    /**
     * @param  int  $index  Which of the month's periods this is, from zero, in
     *                      cutoff order.
     * @param  int  $count  How many periods the firm's month has. Together
     *                      these are what `DeductionSchedule` needs: *which*
     *                      payslip, and whether there is another one.
     */
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly int $index,
        public readonly int $count,
    ) {}

    /**
     * A period between two dates. `PayrollCalendar` is the only caller.
     *
     * Public because the calendar lives beside it rather than inside it, and
     * deliberately not something a controller reaches for: a period built from
     * dates a client sent would be exactly the arbitrary range the class note
     * explains this system does not have.
     */
    public static function between(Carbon $start, Carbon $end, int $index, int $count): self
    {
        return new self($start->copy()->startOfDay(), $end->copy()->startOfDay(), $index, max(1, $count));
    }

    /** Is this the month's first payslip? */
    public function isFirst(): bool
    {
        return $this->index === 0;
    }

    /** Is this the month's only payslip — a firm that pays monthly? */
    public function isOnly(): bool
    {
        return $this->count === 1;
    }

    /**
     * `first`, `second` or `month`.
     *
     * The wire vocabulary the clients already speak, kept as it was when the
     * calendar was fixed at the 1st and the 16th. It stays a *position* in the
     * month rather than a pair of dates, which is why a firm moving its cutoffs
     * to the 10th and the 25th changes what the two periods are without
     * changing what either client has to understand.
     */
    public function half(): string
    {
        return match (true) {
            $this->isOnly() => self::WHOLE_MONTH,
            $this->isFirst() => self::FIRST_HALF,
            default => self::SECOND_HALF,
        };
    }

    /**
     * The day the period closes and payroll is run: the day after the last day
     * worked, which is what a cutoff is.
     *
     * Offered as the default pay date and no more than that — when the money
     * actually leaves the bank is the office's decision, and plenty pay on the
     * 20th.
     */
    public function cutoff(): Carbon
    {
        return $this->end->copy()->addDay();
    }

    /** Days in the period, counting both ends. */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** `1–15 Sep 2026`, or `26 Aug–10 Sep 2026` where the period crosses. */
    public function label(): string
    {
        return self::formatRange($this->start, $this->end);
    }

    /** `1–15` — for a two-button choice where the month is already on screen. */
    public function short(): string
    {
        return $this->start->isSameMonth($this->end)
            ? $this->start->format('j').'–'.$this->end->format('j')
            : $this->start->format('j M').'–'.$this->end->format('j');
    }

    public function matches(self $other): bool
    {
        return $this->start->isSameDay($other->start) && $this->end->isSameDay($other->end);
    }

    /**
     * How a period reads on a list.
     *
     * Static, and shared with `PayRun::periodLabel()`, because a run's stored
     * dates have to read the same way as the period they were opened on — two
     * copies of this format is two chances for a register heading to disagree
     * with the button that produced it.
     *
     * Three shapes, narrowest first: `1–15 Sep 2026` inside one month,
     * `26 Aug–10 Sep 2026` across two, and `26 Dec 2025–10 Jan 2026` across a
     * new year, where dropping the first year would be a lie rather than a
     * tidy-up.
     */
    public static function formatRange(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end)) {
            return $start->format('j').'–'.$end->format('j M Y');
        }

        return $start->isSameYear($end)
            ? $start->format('j M').'–'.$end->format('j M Y')
            : $start->format('j M Y').'–'.$end->format('j M Y');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'half' => $this->half(),
            'index' => $this->index,
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'label' => $this->label(),
            'short' => $this->short(),
            /** The day the period closes, which is the day after it ends. */
            'cutoff' => $this->cutoff()->toDateString(),
            'days' => $this->days(),
        ];
    }
}
