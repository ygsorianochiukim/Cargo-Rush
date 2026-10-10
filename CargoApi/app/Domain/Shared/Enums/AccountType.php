<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::Asset, self::Expense => BalanceSide::Debit,
            self::Liability, self::Equity, self::Income => BalanceSide::Credit,
        };
    }

    public function isPermanent(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Income, self::Expense => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Assets',
            self::Liability => 'Liabilities',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expenses',
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::Asset => 1,
            self::Liability => 2,
            self::Equity => 3,
            self::Income => 4,
            self::Expense => 5,
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
