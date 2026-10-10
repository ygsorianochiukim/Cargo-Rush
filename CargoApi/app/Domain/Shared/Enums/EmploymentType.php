<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum EmploymentType: string
{
    case Trainee = 'trainee';

    case Probationary = 'probationary';
    case Regular = 'regular';
    case Contractual = 'contractual';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::Trainee => 'Trainee',
            self::Probationary => 'Probationary',
            self::Regular => 'Regular',
            self::Contractual => 'Contractual',
            self::PartTime => 'Part-time',
        };
    }

    public function tier(): self
    {
        return match ($this) {
            self::Trainee => self::Trainee,
            self::Probationary => self::Probationary,
            self::Regular, self::Contractual, self::PartTime => self::Regular,
        };
    }

    /** @return self[] */
    public static function tiers(): array
    {
        return [self::Trainee, self::Probationary, self::Regular];
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
