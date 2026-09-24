<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use App\Domain\Payroll\Support\MonthlyShare;

/**
 * Which cutoff the monthly contributions come off.
 *
 * SSS, PhilHealth and Pag-IBIG are **monthly** figures, and payroll here runs
 * twice a month. Something has to decide how a monthly figure lands on two
 * payslips, and offices genuinely differ: some halve each contribution across
 * both cutoffs, and plenty take the whole month's contributions off one of them
 * — usually the second, so the first payslip of the month is the fuller one.
 *
 * All three answers remit the same amount to the same agency at the end of the
 * month. What changes is which payslip is lighter, which is why this is the
 * firm's decision and not a rate: there is no right answer to find, only a
 * policy to record. It lives on the company row beside `vat_rate_bp` for the
 * same reason that does.
 *
 * The withholding tax deliberately does **not** follow this setting. It is
 * charged on what is left after the contributions actually taken on that
 * cutoff, which is the order the BIR computes it in — so moving the
 * contributions onto one cutoff moves the tax with them, as it should.
 */
enum DeductionSchedule: string
{
    /** Half of each contribution on each cutoff. */
    case Split = 'split';

    /** The whole month's contributions on the 1st-to-15th payslip. */
    case FirstCutoff = 'first';

    /** The whole month's contributions on the 16th-to-end payslip. */
    case SecondCutoff = 'second';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Split => 'Split across every run',
            self::FirstCutoff => 'All on the first run',
            self::SecondCutoff => 'All on the second run',
        };
    }

    /** What this policy means on a payslip, for somebody reading the screen. */
    public function detail(int $runs = 2): string
    {
        /**
         * Said in runs rather than in dates, and without "half".
         *
         * These read "1st–15th", "16th–end" and "half of each" — all three true
         * of a fortnightly payroll on the old fixed calendar and none of them
         * true once the cutoff days became the firm's own. A firm closing on
         * the 5th, the 15th and the 25th was told its contributions were split
         * in half across two payslips it does not have.
         *
         * `$runs` is how many the month actually has, so the sentence counts.
         */
        return match ($this) {
            self::Split => $runs <= 1
                ? 'The whole monthly contribution comes off the single payslip.'
                : sprintf(
                    'Each monthly contribution is split %s — %s of it comes off each payslip.',
                    $runs === 2 ? 'in half' : 'evenly across the '.$runs.' runs',
                    $runs === 2 ? 'half' : 'a third',
                ),
            self::FirstCutoff => 'The whole month of SSS, PhilHealth and Pag-IBIG comes off the first payslip. The rest carry none.',
            self::SecondCutoff => 'The whole month of SSS, PhilHealth and Pag-IBIG comes off the second payslip. The rest carry none.',
        };
    }

    /**
     * How much of a monthly contribution one cutoff carries, in centavos.
     *
     * Takes **which run of how many** rather than "is this the first one", and
     * that is a fix rather than a tidy-up. The old signature was a pair of
     * booleans, which can describe a payroll of one run or two and nothing
     * else — so a firm cutting off three times a month had `Split` give the
     * first run half the month's contribution and each of the other two the
     * *other* half, charging one and a half months of SSS, PhilHealth and
     * Pag-IBIG every month. `SecondCutoff` was worse: every run but the first
     * carried the whole month, so two of the three did, and the firm remitted
     * double.
     *
     * Neither announced itself. Each payslip looked ordinary; only the month
     * added up wrong.
     *
     * A count of one is the monthly-payroll case: there is no other payslip for
     * the rest to land on, so the whole contribution comes off whatever the
     * policy says.
     *
     * On `Split` the odd centavo goes to the **last** run, via `MonthlyShare`,
     * so the shares add up to the month exactly. Dividing per run with `intdiv`
     * and hoping is how a firm under-remits a few centavos a month per
     * employee — small, permanent and impossible to explain.
     *
     * @param  int  $index  Which run this is, from zero.
     * @param  int  $count  How many runs the month has.
     */
    public function shareOf(int $monthlyCents, int $index, int $count): int
    {
        if ($count <= 1) {
            return $monthlyCents;
        }

        return match ($this) {
            self::Split => MonthlyShare::forRun($monthlyCents, $index, $count),

            // The whole month on one named run, and nothing on the others —
            // which stays true however many others there are.
            self::FirstCutoff => $index === 0 ? $monthlyCents : 0,

            /**
             * The second run, literally.
             *
             * On a two-run month that is the last one, which is what this has
             * always meant. On a three-run month it is the middle one rather
             * than the last, and that is the honest reading of the name: a firm
             * that wants the contributions on its final run is choosing a
             * different policy, and this enum would need a word for it.
             */
            self::SecondCutoff => $index === 1 ? $monthlyCents : 0,
        };
    }

    /** Does this cutoff carry the contributions at all? */
    public function carriedOn(int $index, int $count): bool
    {
        return $this->shareOf(100, $index, $count) > 0;
    }
}
