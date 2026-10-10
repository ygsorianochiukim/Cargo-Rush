<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum VatTreatment: string
{
    case Vatable = 'vatable';

    case ZeroRated = 'zero_rated';

    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Vatable => 'VAT',
            self::ZeroRated => 'Zero-rated',
            self::Exempt => 'VAT-exempt',
        };
    }

    public function charges(): bool
    {
        return $this === self::Vatable;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
