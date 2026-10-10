<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum BookingSource: string
{
    case CargoRush = 'cargo_rush';

    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::CargoRush => 'Cargo Rush',
            self::Direct => 'Direct booking',
        };
    }

    public function collectedByCarrier(): bool
    {
        return $this === self::CargoRush;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
