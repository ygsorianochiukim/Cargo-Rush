<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PayrollScope: string
{
    case Office = 'office';

    case Crew = 'crew';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office payroll',
            self::Crew => 'Crew payroll',
        };
    }

    public function who(): string
    {
        return match ($this) {
            self::Office => 'the office and admin staff',
            self::Crew => 'the company’s own drivers and helpers',
        };
    }

    public function referencePrefix(): string
    {
        return match ($this) {
            self::Office => 'PR',
            self::Crew => 'CP',
        };
    }

    public function route(): string
    {
        return match ($this) {
            self::Office => '/payroll',
            self::Crew => '/payroll/crew',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
