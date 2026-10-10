<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum BalanceSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    public function sign(self $movement): int
    {
        return $this === $movement ? 1 : -1;
    }

    public function label(): string
    {
        return $this === self::Debit ? 'Debit' : 'Credit';
    }
}
