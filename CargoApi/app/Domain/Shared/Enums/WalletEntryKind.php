<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * What a row in a partner's wallet is.
 *
 * Five kinds, and they fall into two pairs and a remainder. `earning` and
 * `commission` are what a delivered run writes — one or the other, never both,
 * decided by who collected the money. `payout` and `remittance` are the two
 * directions a balance is settled in. `adjustment` is the office correcting
 * something by hand, which every money system needs and no money system should
 * make easy.
 *
 * The sign of the amount is not stored here: it is on the row, and this enum
 * says which sign is the *only* honest one for each kind. `signFor()` is what
 * enforces it, so nothing anywhere can write a negative earning.
 */
enum WalletEntryKind: string
{
    /**
     * The partner's share of a run the haulier billed and collected.
     *
     * Positive: the haulier is holding the customer's money and owes this much
     * of it onwards.
     */
    case Earning = 'earning';

    /**
     * The haulier's cut of a run the partner collected themselves.
     *
     * Negative: the partner has the customer's money and owes this much of it
     * back.
     */
    case Commission = 'commission';

    /** Money handed to the partner, clearing what was owed to them. Negative. */
    case Payout = 'payout';

    /** Money the partner paid in, clearing what they owed. Positive. */
    case Remittance = 'remittance';

    /**
     * A correction, in either direction, with a reason written on it.
     *
     * The only kind whose sign is the caller's to choose, and deliberately the
     * only one: a wallet that can be moved to an arbitrary figure without a
     * note is not a record of anything.
     */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Trip earning',
            self::Commission => 'Commission',
            self::Payout => 'Payout',
            self::Remittance => 'Remittance',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Which way this kind must move the balance: 1, -1, or 0 for "as given".
     *
     * Applied to the magnitude the caller passes, so a service that computes a
     * commission does not have to remember to negate it — and cannot forget.
     */
    public function signFor(): int
    {
        return match ($this) {
            self::Earning, self::Remittance => 1,
            self::Commission, self::Payout => -1,
            self::Adjustment => 0,
        };
    }

    /** Does this kind describe a haul, rather than a settlement? */
    public function isFromWork(): bool
    {
        return $this === self::Earning || $this === self::Commission;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
