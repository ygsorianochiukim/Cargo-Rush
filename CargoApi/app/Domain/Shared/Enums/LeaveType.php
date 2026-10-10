<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum LeaveType: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Emergency = 'emergency';
    case Unpaid = 'unpaid';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case Bereavement = 'bereavement';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Vacation',
            self::Sick => 'Sick',
            self::Emergency => 'Emergency',
            self::Unpaid => 'Unpaid',
            self::Maternity => 'Maternity',
            self::Paternity => 'Paternity',
            self::Bereavement => 'Bereavement',
        };
    }

    public function isPaid(): bool
    {
        return $this !== self::Unpaid;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
