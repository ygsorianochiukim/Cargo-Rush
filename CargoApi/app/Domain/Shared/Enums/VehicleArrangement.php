<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum VehicleArrangement: string
{
    case Owned = 'owned';

    case Rented = 'rented';

    case RentedShare = 'rented_share';

    case SubContracted = 'subcontracted';

    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Owned',
            self::Rented => 'Rented',
            self::RentedShare => 'Rented — revenue share',
            self::SubContracted => 'Sub-contracted',
        };
    }

    public function sharesRevenue(): bool
    {
        return $this === self::RentedShare || $this === self::SubContracted;
    }

    public function chargesRent(): bool
    {
        return $this === self::Rented;
    }

    public function defaultShareBp(): ?int
    {
        return match ($this) {
            self::RentedShare => 1500,
            self::SubContracted => 1200,
            default => null,
        };
    }

    public function isHired(): bool
    {
        return $this !== self::Owned;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
