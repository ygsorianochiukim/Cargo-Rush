<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PayComponentKind: string
{
    case Earning = 'earning';

    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
        };
    }

    public function sign(): string
    {
        return $this === self::Earning ? '+' : '−';
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
