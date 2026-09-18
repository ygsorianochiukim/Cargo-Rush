<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Which side of a payslip a component lands on.
 *
 * Two cases, and the reason it is an enum rather than the sign of the amount:
 * a deduction is stored as a **positive** number that comes off, exactly as the
 * statutory columns are. A signed amount would make "a negative deduction"
 * something somebody could type into a form, and there is no reading of that
 * anybody could defend on a payslip.
 */
enum PayComponentKind: string
{
    /** Paid on top of the basic. Allowances, COLA, a monthly bonus. */
    case Earning = 'earning';

    /** Taken off. A uniform, a loan repayment, a canteen tab. */
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
        };
    }

    /** How it reads beside an amount on a payslip. */
    public function sign(): string
    {
        return $this === self::Earning ? '+' : '−';
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
