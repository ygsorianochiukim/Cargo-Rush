<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Which way a row of the store tab moves the balance.
 *
 * A *pautang* is a running account, not a loan with a schedule: somebody takes
 * a sack of rice on Tuesday and two tins on Friday, and the cutoff takes off
 * whatever the office decides. So the ledger has two kinds of row and the
 * balance is the difference, which is the only shape that needs no decision
 * about *which* rice a ₱500 deduction paid for.
 *
 * Both are stored as positive amounts. A signed column would make "a negative
 * charge" typeable and leave nobody able to say what it meant — the same
 * argument `PayComponentKind` makes about deductions.
 */
enum StoreCreditKind: string
{
    /** Goods taken against pay. The balance goes up. */
    case Charge = 'charge';

    /** Money returned — off a payslip, or handed over in cash. Down. */
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::Payment => 'Payment',
        };
    }

    /**
     * What this row does to the balance: +1 or -1.
     *
     * The one place the direction is written down, so a report, a payslip and
     * the balance on screen cannot disagree about which way a row points.
     */
    public function sign(): int
    {
        return $this === self::Charge ? 1 : -1;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
