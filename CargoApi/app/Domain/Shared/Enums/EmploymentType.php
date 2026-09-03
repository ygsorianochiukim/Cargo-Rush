<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How somebody is engaged.
 *
 * A short closed list, unlike `position`, which is deliberately free text: the
 * job titles a haulier invents are endless, but the ways it can employ someone
 * are set by labour law rather than by the office.
 */
enum EmploymentType: string
{
    case Regular = 'regular';
    case Probationary = 'probationary';
    case Contractual = 'contractual';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Probationary => 'Probationary',
            self::Contractual => 'Contractual',
            self::PartTime => 'Part-time',
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
