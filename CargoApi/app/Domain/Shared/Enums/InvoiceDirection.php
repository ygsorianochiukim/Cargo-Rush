<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/** `receivable` is money in, `payable` is money out. */
enum InvoiceDirection: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
