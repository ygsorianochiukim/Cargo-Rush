<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Why somebody is off.
 *
 * A closed list, unlike a job title: leave types come from policy and labour
 * law, not from whoever is filling in the form. The distinction that matters
 * downstream is `paid` — unpaid leave still takes a driver off the road, so it
 * counts against availability, but it does not count against an entitlement.
 */
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

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
