<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\NavItem;
use Illuminate\Database\Seeder;

/**
 * The sidebar and the tab bar, as rows.
 *
 * This is the module map from DESIGN.md section 5.1 and 5.2 in one place. Both
 * clients render whatever this produces, so adding a module starts here.
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // Operations
            ['dashboard', 'Dashboard', 'dashboard', '/dashboard', 10, true, true, 'Operations', null, null],
            ['gps', 'GPS Dashboard', 'map-pin', '/gps', 20, true, true, 'Operations', 'gps.view', null],
            ['trips', 'Trip Management', 'route', '/trips', 30, true, true, 'Operations', 'trips.view', 'trips.requests'],
            ['dispatch', 'Dispatch Monitoring', 'dispatch', '/dispatch', 40, false, true, 'Operations', 'dispatch.view', null],
            ['delivery-logs', 'Delivery Logs', 'clipboard', '/delivery-logs', 50, true, true, 'Operations', 'delivery.view', null],

            // Assets
            ['vehicles', 'Vehicle Management', 'fleet', '/vehicles', 60, false, true, 'Assets', 'vehicles.view', null],
            ['drivers', 'Drivers Management', 'profile', '/drivers', 70, false, true, 'Assets', 'drivers.view', null],
            ['fuel', 'Fuel Expense', 'fuel', '/fuel', 80, false, true, 'Assets', 'fuel.view', null],

            // Finance — the workbook
            ['monitoring', 'Trip Monitoring', 'clipboard', '/monitoring', 85, false, true, 'Finance', 'finance.view', null],
            ['profitability', 'Profitability', 'gauge', '/profitability', 86, false, true, 'Finance', 'finance.view', null],
            ['summary', 'Quarterly Summary', 'calendar', '/summary', 87, false, true, 'Finance', 'finance.view', null],

            ['expenses', 'Other Expenses', 'wallet', '/expenses', 88, false, true, 'Finance', 'expenses.view', null],
            ['sales', 'Sales Report', 'trend', '/sales', 89, false, true, 'Finance', 'sales.view', null],

            // Business
            ['customers', 'Customer Management', 'customers', '/customers', 90, false, true, 'Business', 'customers.view', null],
            ['billing', 'Billing & Invoice', 'billing', '/billing', 100, false, true, 'Business', 'billing.view', null],
            ['pricing', 'Rate Card', 'tag', '/pricing', 105, false, true, 'Business', 'pricing.view', null],

            // HR. Two of these carry badges, because both count something
            // sitting on somebody's desk: a CV nobody has read, and a leave
            // request nobody has decided.
            ['employees', 'Employees', 'badge', '/employees', 106, false, true, 'HR', 'hr.view', null],
            ['applicants', 'Applicants', 'inbox', '/applicants', 107, false, true, 'HR', 'hr.view', 'applicants.open'],
            ['time-off', 'Leave & Undertime', 'calendar', '/time-off', 108, false, true, 'HR', 'hr.view', 'timeoff.open'],
            ['performance', 'Performance', 'gauge', '/performance', 109, false, true, 'HR', 'hr.view', null],
            // Its own permission, not `hr.manage`: whoever runs the roster does
            // not thereby get to grant themselves the ledger.
            ['access', 'Access Control', 'shield', '/access', 110, false, true, 'HR', 'access.view', null],

            // Support. Numbered after HR so the two groups do not interleave.
            ['incidents', 'Incident Management', 'incident', '/incidents', 130, false, true, 'Support', 'incidents.view', 'incidents.open'],
            ['notifications', 'Notifications', 'bell', '/notifications', 140, false, true, 'Support', 'notifications.view', 'notifications.unread'],

            // Mobile-only tabs. `web: false` keeps them out of the sidebar —
            // the driver app's five tabs are a different product, not a subset
            // rendered small (DESIGN.md section 5.2).
            ['cargo', 'Cargo', 'shipments', '/cargo', 25, true, false, 'Driver', 'delivery.view', null],
            ['tracking', 'Tracking', 'map-pin', '/tracking', 35, true, false, 'Driver', 'gps.write', null],
            ['inspect', 'Inspect', 'clipboard', '/inspect', 45, true, false, 'Driver', 'inspection.write', null],
            ['more', 'More', 'profile', '/more', 130, true, false, 'Driver', null, null],

            // Customer-only tabs. `web: false` for the same reason the driver's
            // are: `cargoApp` is one app that opens on a different set of tabs
            // depending on who signed in, and neither set belongs in the
            // back-office sidebar. The permissions are what keep the two sets
            // apart — a customer holds `portal.*` and nothing else, so the
            // driver's tabs cannot come back for them and theirs cannot come
            // back for a driver.
            ['home', 'Home', 'dashboard', '/', 24, true, false, 'Customer', 'portal.view', null],
            ['requests', 'Deliveries', 'shipments', '/orders', 26, true, false, 'Customer', 'portal.view', null],
            ['request', 'Request', 'plus', '/request', 27, true, false, 'Customer', 'portal.request', null],
            ['invoices', 'Invoices', 'billing', '/invoices', 28, true, false, 'Customer', 'portal.view', null],
        ];

        foreach ($items as [$key, $label, $icon, $route, $order, $mobile, $web, $group, $permission, $badge]) {
            NavItem::updateOrCreate(['key' => $key], [
                'label' => $label,
                'icon' => $icon,
                'route' => $route,
                'order' => $order,
                'mobile' => $mobile,
                'web' => $web,
                'group' => $group,
                'permission' => $permission,
                'badge_source' => $badge,
            ]);
        }
    }
}
