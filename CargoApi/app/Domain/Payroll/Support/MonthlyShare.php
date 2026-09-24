<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

/**
 * One pay run's share of a monthly figure.
 *
 * A monthly salary and a monthly contribution are both stated per month and
 * paid per run, so both have to be cut into as many pieces as the firm has
 * cutoffs — and the pieces have to add back up to the month exactly.
 *
 * ## Why this exists rather than the arithmetic being written twice
 *
 * It was written twice, and both copies assumed two runs:
 *
 *     $cutoff['first'] ? intdiv($rate, 2) : $rate - intdiv($rate, 2)
 *
 * which is correct for a fortnightly payroll and silently wrong for any other.
 * On a firm cutting off three times a month — the 5th, the 15th and the 25th —
 * the first run paid half a month's salary and the other two paid half each, so
 * everybody was paid **one and a half times** what they earn. The statutory
 * contributions had the same shape and the same failure.
 *
 * Nothing announced it. The figures looked plausible on every individual
 * payslip; only the month added up wrong.
 *
 * ## The remainder goes on the last run
 *
 * ₱10,000 across three runs is 3,333 / 3,333 / 3,334. Deliberately not rounded
 * per run, which would pay 3,333 three times and quietly keep a peso, and not
 * loaded onto the first, which would make the first payslip of every month
 * differ from the others for no reason a payslip can explain.
 *
 * The guarantee this class exists for: **the shares sum to the monthly figure**,
 * for any count, with no peso invented or lost.
 */
final class MonthlyShare
{
    /**
     * @param  int  $monthlyCents  The whole month's figure.
     * @param  int  $index  Which run this is, from zero.
     * @param  int  $count  How many runs the month has.
     */
    public static function forRun(int $monthlyCents, int $index, int $count): int
    {
        // One run a month carries the lot, and a nonsense count is treated as
        // one rather than dividing by zero: a payslip is the wrong place to
        // discover a bad calendar.
        if ($count <= 1) {
            return $monthlyCents;
        }

        $base = intdiv($monthlyCents, $count);

        // The last run carries whatever the division left, so the month is
        // exact. On ₱10,000 over three that is the odd peso.
        return $index >= $count - 1
            ? $monthlyCents - $base * ($count - 1)
            : $base;
    }
}
