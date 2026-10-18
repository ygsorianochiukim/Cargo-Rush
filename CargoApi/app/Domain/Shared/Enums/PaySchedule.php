<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PaySchedule: string
{
    case Monthly = 'monthly';

    case Cutoff = 'cutoff';

    case OnReceipt = 'on_receipt';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Cutoff => 'Every cutoff',
            self::OnReceipt => 'Cash on receipt',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
