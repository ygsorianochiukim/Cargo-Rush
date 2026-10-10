<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PayBasis: string
{
    case Monthly = 'monthly';

    case Daily = 'daily';

    case PerTrip = 'per_trip';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly salary',
            self::Daily => 'Daily rate',
            self::PerTrip => 'Per trip',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Monthly => 'a month',
            self::Daily => 'a day',
            self::PerTrip => 'a trip',
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::Monthly => 'A fixed monthly salary, split across the month’s payslips.',
            self::Daily => 'The daily rate for each day they were out, counted from the truck sheet.',
            self::PerTrip => 'The trip rate for each haul they delivered in the period.',
        };
    }

    public function countsWork(): bool
    {
        return $this !== self::Monthly;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
