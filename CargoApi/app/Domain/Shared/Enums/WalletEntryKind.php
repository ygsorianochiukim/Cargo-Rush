<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum WalletEntryKind: string
{
    case Earning = 'earning';

    case Commission = 'commission';

    case Payout = 'payout';

    case Remittance = 'remittance';

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

    public function signFor(): int
    {
        return match ($this) {
            self::Earning, self::Remittance => 1,
            self::Commission, self::Payout => -1,
            self::Adjustment => 0,
        };
    }

    public function isFromWork(): bool
    {
        return $this === self::Earning || $this === self::Commission;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
