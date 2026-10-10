<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum JournalCategory: string
{
    case Operations = 'operations';
    case Payroll = 'payroll';
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Billing = 'billing';
    case Collection = 'collection';
    case Disbursement = 'disbursement';
    case Financing = 'financing';
    case Tax = 'tax';
    case Adjustment = 'adjustment';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Operations => 'Operations',
            self::Payroll => 'Payroll',
            self::Fuel => 'Fuel',
            self::Maintenance => 'Maintenance',
            self::Billing => 'Billing',
            self::Collection => 'Collection',
            self::Disbursement => 'Disbursement',
            self::Financing => 'Financing',
            self::Tax => 'Tax',
            self::Adjustment => 'Adjustment',
            self::Opening => 'Opening balance',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Operations => 'route',
            self::Payroll => 'badge',
            self::Fuel => 'fuel',
            self::Maintenance => 'fleet',
            self::Billing => 'billing',
            self::Collection => 'wallet',
            self::Disbursement => 'tag',
            self::Financing => 'trend',
            self::Tax => 'shield',
            self::Adjustment => 'clipboard',
            self::Opening => 'calendar',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
