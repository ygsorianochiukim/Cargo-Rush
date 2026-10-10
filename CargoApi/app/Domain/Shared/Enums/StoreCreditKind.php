<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum StoreCreditKind: string
{
    case Charge = 'charge';

    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::Payment => 'Payment',
        };
    }

    public function sign(): int
    {
        return $this === self::Charge ? 1 : -1;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
