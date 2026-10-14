<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum CrewRole: string
{
    case Driver = 'driver';

    case Helper = 'helper';

    public function label(): string
    {
        return match ($this) {
            self::Driver => 'Driver',
            self::Helper => 'Helper',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
