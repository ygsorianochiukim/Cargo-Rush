<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How a component's amount is arrived at.
 *
 * A peso figure, or a share of the person's monthly basic. The percentage is
 * worth having rather than making the office do the arithmetic: an allowance
 * set at 10% of the basic follows a rise on its own, and a firm that has to
 * recompute it by hand on every promotion will eventually not — which surfaces
 * as one person quietly underpaid for a year.
 *
 * Both produce a **monthly** figure. What happens to it after that is
 * `PayComponentSchedule`, which is a separate question and deliberately not
 * folded in here: "10% of the basic" and "on the second payslip" are two things
 * an office decides independently.
 */
enum PayComponentBasis: string
{
    /** A peso amount, in centavos like every other money figure here. */
    case Fixed = 'fixed';

    /** Basis points of the monthly basic (450 = 4.5%). */
    case PercentOfBasic = 'percent_of_basic';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'A fixed amount',
            self::PercentOfBasic => 'A percentage of the basic',
        };
    }

    /**
     * The monthly figure this basis produces.
     *
     * Rounded to the centavo on the way out, so nothing here produces a
     * fraction of a centavo that a payslip would then have to hide — the same
     * rule `StatutoryDeductions` follows.
     */
    public function monthlyCents(int $amountCents, int $rateBp, int $monthlyBasicCents): int
    {
        return match ($this) {
            self::Fixed => max(0, $amountCents),
            self::PercentOfBasic => (int) round((max(0, $monthlyBasicCents) * max(0, $rateBp)) / 10_000),
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
