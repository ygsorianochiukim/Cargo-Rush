<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PaymentSourceKind: string
{
    case Cash = 'cash';

    case CreditCard = 'credit_card';

    case FuelCard = 'fuel_card';

    case Bank = 'bank';

    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::CreditCard => 'Credit card',
            self::FuelCard => 'Fuel card',
            self::Bank => 'Bank account',
            self::EWallet => 'E-wallet',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
