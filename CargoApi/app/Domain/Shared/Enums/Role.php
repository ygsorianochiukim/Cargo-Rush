<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Who is holding the app. `administrator`, `dispatcher` and `accountant` sit
 * at the back office (CargoUI); `driver`, `customer` and `trucker` are on a
 * handset (cargoApp), which is one app that opens on a different home screen
 * for each of them.
 *
 * The three handset roles are three products, not three views of one. A driver
 * is an employee working the run they were given; a customer is a firm booking
 * one; a trucker is an owner-operator choosing which work to take and watching
 * what it earned them. None of the three sets of screens would function for
 * either of the others — a driver has no wallet, a trucker has no payslip, and
 * a customer has neither.
 */
enum Role: string
{
    case Administrator = 'administrator';
    case Dispatcher = 'dispatcher';
    case Accountant = 'accountant';
    case Driver = 'driver';
    case Customer = 'customer';
    /**
     * An owner-operator hauling for the company with their own truck.
     *
     * Not a driver, and the distinction is the whole reason for the case: a
     * driver is on the payroll and is told which run to take, while a trucker
     * is paid a share of what a run billed and decides for themselves whether
     * to take it. Giving them the driver role would have handed them the
     * employee's screens and no wallet.
     */
    case Trucker = 'trucker';

    /** The display string. The client uppercases it — DESIGN.md section 7.2. */
    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Dispatcher => 'Dispatcher',
            self::Accountant => 'Accountant',
            self::Driver => 'Driver',
            self::Customer => 'Customer',
            self::Trucker => 'Trucker',
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
                'finance.manage',
                // The general journal and the general ledger. The accountant's
                // own book: posting to it decides what every statement
                // afterwards says. Payroll is theirs to run for the same
                // reason — it is the largest entry of the month.
                'accounting.view', 'accounting.manage',
                'payroll.view', 'payroll.manage',
                'customers.view', 'billing.view', 'billing.manage',
                'pricing.view', 'pricing.manage', 'expenses.view', 'expenses.manage',
                'sales.view', 'notifications.view',
            ],
            /**
             * A `write` for each thing a driver does, and no `view` or `manage`
             * for any of them. `incidents.write` is the newest and follows the
             * same rule: the person who was there reports what happened, and
             * reading the log, editing a write-up and closing one out stay with
             * the office.
             */
            self::Driver => [
                'trips.view', 'gps.write', 'delivery.view', 'delivery.write',
                'inspection.write', 'incidents.write', 'finance.write',
                'notifications.view',
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
            /**
             * A partner's own work and their own money, and nothing else.
             *
             * Note what is absent, and why each one is.
             *
             * No `trips.view` — that is the whole board. The open jobs and
             * their own runs come back from the `partner.*` endpoints, which
             * are scoped to their `truckers` row exactly as the driver's are
             * scoped to a `drivers` row and the customer's to a `customers`
             * row. One forgotten `where` on the trip board would show a
             * partner every load in the company.
             *
             * No `inspection.write` — a pre-trip check is the haulier looking
             * over its own unit before it rolls. A partner's truck is not the
             * haulier's to clear, and a checklist against somebody else's
             * maintenance would be a record nobody could act on.
             *
             * No `finance.write` — that is the day's ledger sheet for a company
             * truck. A partner's diesel and tyres are their own business; what
             * they see of the money is the wallet, which is `partner.view`.
             *
             * `delivery.write` and `gps.write` are held for the same reason a
             * driver holds them: whoever is at the door signs the run off, and
             * whoever is on the road reports where they are.
             */
            self::Trucker => [
                'partner.view', 'partner.jobs', 'gps.write',
                'delivery.view', 'delivery.write', 'incidents.write',
                'notifications.view',
            ],
        };
    }
}
