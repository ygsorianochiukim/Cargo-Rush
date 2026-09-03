<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Who is holding the app. `administrator`, `dispatcher` and `accountant` sit
 * at the back office (CargoUI); `driver` and `customer` are on a handset
 * (cargoApp), which is one app that opens on a different home screen for each
 * of them.
 */
enum Role: string
{
    case Administrator = 'administrator';
    case Dispatcher = 'dispatcher';
    case Accountant = 'accountant';
    case Driver = 'driver';
    case Customer = 'customer';

    /** The display string. The client uppercases it — DESIGN.md section 7.2. */
    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Dispatcher => 'Dispatcher',
            self::Accountant => 'Accountant',
            self::Driver => 'Driver',
            self::Customer => 'Customer',
        };
    }

    /**
     * Permission strings this role carries. `*` is every permission and only
     * the administrator gets it.
     *
     * @return string[]
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => ['*'],
            self::Dispatcher => [
                'trips.view', 'trips.manage', 'gps.view', 'dispatch.view',
                'delivery.view', 'vehicles.view', 'drivers.view',
                'incidents.view', 'incidents.manage', 'notifications.view',
            ],
            /**
             * The rate card belongs here rather than with the dispatcher.
             * Editing a bracket changes what every future run is billed, which
             * is a money decision — the desk quotes from the card, it does not
             * redraw it.
             */
            self::Accountant => [
                'trips.view', 'fuel.view', 'fuel.manage', 'finance.view',
                'finance.manage', 'customers.view', 'billing.view', 'billing.manage',
                'pricing.view', 'pricing.manage', 'expenses.view', 'expenses.manage',
                'sales.view', 'notifications.view',
            ],
            self::Driver => [
                'trips.view', 'gps.write', 'delivery.view', 'delivery.write',
                'inspection.write', 'finance.write', 'notifications.view',
            ],
            /**
             * A customer books their own work and reads their own money, and
             * nothing else. Note what is absent: no `trips.view`, because that
             * is the whole board — a customer's own deliveries come back from
             * the portal endpoints, which are scoped to their record the same
             * way the driver's are scoped to theirs.
             */
            self::Customer => [
                'portal.view', 'portal.request', 'notifications.view',
            ],
        };
    }
}
