<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum Role: string
{
    case Administrator = 'administrator';
    case Dispatcher = 'dispatcher';
    case Accountant = 'accountant';
    case Driver = 'driver';
    case Customer = 'customer';
    case Trucker = 'trucker';
    case TruckerDriver = 'trucker_driver';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Dispatcher => 'Dispatcher',
            self::Accountant => 'Accountant',
            self::Driver => 'Driver',
            self::Customer => 'Customer',
            self::Trucker => 'Trucker',
            self::TruckerDriver => 'Trucker driver',
        };
    }

    /** @return string[] */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => ['*'],
            self::Dispatcher => [
                'trips.view', 'trips.manage', 'gps.view', 'dispatch.view',
                'delivery.view', 'vehicles.view', 'drivers.view',
                'incidents.view', 'incidents.manage', 'notifications.view',
            ],
            self::Accountant => [
                'trips.view', 'fuel.view', 'fuel.manage', 'finance.view',
                'finance.manage',
                'accounting.view', 'accounting.manage',
                'payroll.view', 'payroll.manage',
                'customers.view', 'billing.view', 'billing.manage',
                'pricing.view', 'pricing.manage', 'expenses.view', 'expenses.manage',
                'sales.view', 'notifications.view',
            ],
            self::Driver => [
                'trips.view', 'gps.write', 'delivery.view', 'delivery.write',
                'inspection.write', 'incidents.write', 'finance.write',
                'notifications.view',
            ],
            self::Customer => [
                'portal.view', 'portal.request', 'notifications.view',
            ],
            self::Trucker => [
                'partner.view', 'partner.jobs', 'gps.write',
                'delivery.view', 'delivery.write', 'incidents.write',
                'notifications.view',
            ],
            self::TruckerDriver => [
                'crew.trips', 'gps.write', 'delivery.view', 'delivery.write',
                'notifications.view',
            ],
        };
    }
}
