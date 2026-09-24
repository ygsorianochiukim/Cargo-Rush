<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Which payslip of the month a component lands on.
 *
 * The question `DeductionSchedule` answers for the statutory contributions,
 * asked again for the firm's own allowances and deductions — plus one case the
 * contributions do not need.
 *
 * ## Why `each_run` exists and the others are not enough
 *
 * SSS, PhilHealth and Pag-IBIG are unambiguously monthly figures; the only
 * question is which payslip carries them. A firm's own components are not.
 * "₱2,000 rice allowance" almost always means a month of it, split or loaded
 * onto one cutoff. "₱500 a payslip" means ₱500 a payslip, and on a semi-monthly
 * payroll that is ₱1,000 a month. Both are ordinary, neither can be guessed
 * from the number, and guessing wrong is a doubling or a halving of somebody's
 * allowance that nobody notices for months.
 *
 * So the office says which, and the four cases are deliberately named for what
 * the office would say rather than for the arithmetic.
 *
 * ## Why it delegates
 *
 * The three monthly cases hand off to `DeductionSchedule`, which already owns
 * the splitting rule — including the one about the odd centavo landing on the
 * second payslip so the two halves add to the month exactly. A second
 * implementation of that here is how a firm ends up paying a rice allowance
 * that is one centavo short twelve times a year.
 */
enum PayComponentSchedule: string
{
    /** The full amount on every payslip. Not a monthly figure at all. */
    case EachRun = 'each_run';

    /** A monthly figure, halved across the month's two payslips. */
    case MonthlySplit = 'monthly_split';

    /** A monthly figure, all of it on the month's first payslip. */
    case FirstCutoff = 'first_cutoff';

    /** A monthly figure, all of it on the month's second payslip. */
    case SecondCutoff = 'second_cutoff';

    public function label(): string
    {
        return match ($this) {
            self::EachRun => 'On every payslip',
            self::MonthlySplit => 'Monthly, split across both cutoffs',
            self::FirstCutoff => 'Monthly, all on the first cutoff',
            self::SecondCutoff => 'Monthly, all on the second cutoff',
        };
    }

    /** What this means for the person being paid, in a sentence. */
    public function detail(): string
    {
        return match ($this) {
            self::EachRun => 'The full amount appears on every payslip, so a semi-monthly payroll pays it twice a month.',
            self::MonthlySplit => 'Half the monthly amount comes off each payslip.',
            self::FirstCutoff => 'The whole monthly amount is on the first payslip. The second carries none.',
            self::SecondCutoff => 'The whole monthly amount is on the second payslip. The first carries none.',
        };
    }

    /** Is the amount a monthly figure, or a per-payslip one? */
    public function isMonthly(): bool
    {
        return $this !== self::EachRun;
    }

    /**
     * How much of the figure this payslip carries, in centavos.
     *
     * `$isOnlyRun` is the monthly-payroll case: there is no second payslip for
     * anything to land on, so the whole figure comes off whichever one there
     * is. A firm that pays once a month has no cutoff to choose between — and
     * `each_run` and the three monthly cases all mean the same thing there,
     * which is correct rather than a coincidence.
     */
    public function shareOf(int $monthlyCents, bool $isFirstCutoff, bool $isOnlyRun = false): int
    {
        return match ($this) {
            // Per payslip, so every payslip gets all of it — including the
            // single run of a monthly payroll.
            self::EachRun => $monthlyCents,
            self::MonthlySplit => DeductionSchedule::Split->shareOf($monthlyCents, $isFirstCutoff, $isOnlyRun),
            self::FirstCutoff => DeductionSchedule::FirstCutoff->shareOf($monthlyCents, $isFirstCutoff, $isOnlyRun),
            self::SecondCutoff => DeductionSchedule::SecondCutoff->shareOf($monthlyCents, $isFirstCutoff, $isOnlyRun),
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
