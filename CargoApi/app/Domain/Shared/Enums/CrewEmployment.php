<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum CrewEmployment: string
{
    case Company = 'company';

    case Subcontractor = 'subcontractor';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company personnel',
            self::Subcontractor => 'Sub-contractor’s crew',
        };
    }

    public function onPayroll(): bool
    {
        return $this === self::Company;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
