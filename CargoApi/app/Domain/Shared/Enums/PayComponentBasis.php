<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PayComponentBasis: string
{
    case Fixed = 'fixed';

    case PercentOfBasic = 'percent_of_basic';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'A fixed amount',
            self::PercentOfBasic => 'A percentage of the basic',
        };
    }

    public function monthlyCents(int $amountCents, int $rateBp, int $monthlyBasicCents): int
    {
        return match ($this) {
            self::Fixed => max(0, $amountCents),
            self::PercentOfBasic => (int) round((max(0, $monthlyBasicCents) * max(0, $rateBp)) / 10_000),
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
