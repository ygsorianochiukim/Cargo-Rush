<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\NavItem;
use Illuminate\Database\Seeder;

class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['dashboard', 'Dashboard', 'dashboard', '/dashboard', 100, true, true, 'Operations', null, null],
            ['fleet-dashboard', 'Fleet Dashboard', 'fleet', '/fleet-dashboard', 105, false, true, 'Operations', null, null],
            ['trips', 'Trip Management', 'route', '/trips', 110, true, true, 'Operations', 'trips.view', 'trips.requests'],
            ['dispatch', 'Dispatch Monitoring', 'dispatch', '/dispatch', 120, false, true, 'Operations', 'dispatch.view', null],
            ['delivery-logs', 'Delivery Logs', 'clipboard', '/delivery-logs', 130, true, true, 'Operations', 'delivery.view', null],
            ['gps', 'GPS Dashboard', 'map-pin', '/gps', 140, true, true, 'Operations', 'gps.view', null],

            ['vehicles', 'Vehicle Management', 'fleet', '/vehicles', 200, false, true, 'Fleet', 'vehicles.view', null],
            ['drivers', 'Drivers Management', 'profile', '/drivers', 210, false, true, 'Fleet', 'drivers.view', null],
            ['helpers', 'Helpers Management', 'customers', '/helpers', 215, false, true, 'Fleet', 'drivers.view', null],
            ['truckers', 'Truckers', 'fleet', '/truckers', 220, false, true, 'Fleet', 'truckers.view', 'truckers.pending'],
            ['fuel', 'Fuel Expense', 'fuel', '/fuel', 230, false, true, 'Fleet', 'fuel.view', null],

            ['customers', 'Customer Management', 'customers', '/customers', 300, false, true, 'Sales & Billing', 'customers.view', null],
            ['pricing', 'Rate Card', 'tag', '/pricing', 310, false, true, 'Sales & Billing', 'pricing.view', null],
            ['billing', 'Billing & Invoice', 'billing', '/billing', 320, false, true, 'Sales & Billing', 'billing.view', null],
            ['payables', 'Payables', 'billing', '/payables', 330, false, true, 'Sales & Billing', 'finance.view', null],
            ['expenses', 'Other Expenses', 'wallet', '/expenses', 340, false, true, 'Sales & Billing', 'expenses.view', null],
            ['suppliers', 'Suppliers', 'customers', '/suppliers', 350, false, true, 'Sales & Billing', 'suppliers.view', null],
            ['maintenance', 'Truck Maintenance', 'fleet', '/maintenance', 360, false, true, 'Sales & Billing', 'expenses.view', null],

            ['monitoring', 'Trip Monitoring', 'clipboard', '/monitoring', 400, false, true, 'Reports', 'finance.view', null],
            ['profitability', 'Profitability', 'gauge', '/profitability', 410, false, true, 'Reports', 'finance.view', null],
            ['summary', 'Quarterly Summary', 'calendar', '/summary', 420, false, true, 'Reports', 'finance.view', null],
            ['truck-report', 'Truck Report', 'fleet', '/truck-report', 425, false, true, 'Reports', 'finance.view', null],
            ['sales', 'Sales Report', 'trend', '/sales', 430, false, true, 'Reports', 'sales.view', null],

            ['statements', 'Financial Statements', 'trend', '/statements', 500, false, true, 'Accounting', 'accounting.view', null],
            ['journal', 'General Journal', 'clipboard', '/journal', 510, false, true, 'Accounting', 'accounting.view', null],
            ['ledger', 'General Ledger', 'gauge', '/ledger', 520, false, true, 'Accounting', 'accounting.view', null],
            ['accounts', 'Chart of Accounts', 'tag', '/accounts', 530, false, true, 'Accounting', 'accounting.view', null],
            ['payment-sources', 'Wallets & Cards', 'wallet', '/payment-sources', 540, false, true, 'Accounting', 'accounting.view', null],

            ['employees', 'Employees', 'badge', '/employees', 600, false, true, 'People', 'hr.view', null],
            ['applicants', 'Applicants', 'inbox', '/applicants', 610, false, true, 'People', 'hr.view', 'applicants.open'],
            ['time-off', 'Leave & Undertime', 'calendar', '/time-off', 620, false, true, 'People', 'hr.view', 'timeoff.open'],
            ['performance', 'Performance', 'gauge', '/performance', 630, false, true, 'People', 'hr.view', null],
            ['payroll', 'Payroll', 'wallet', '/payroll', 640, false, true, 'People', 'payroll.view', null],

            ['asset-inventory', 'Asset Inventory', 'shipments', '/asset-inventory', 700, false, true, 'Custodian', 'assets.view', null],
            ['assets', 'Asset List', 'clipboard', '/assets', 710, false, true, 'Custodian', 'assets.view', null],
            ['asset-monitoring', 'Asset Monitoring', 'profile', '/asset-monitoring', 720, false, true, 'Custodian', 'assets.view', null],
            ['asset-reassignment', 'Asset Re-assignment', 'route', '/asset-reassignment', 730, false, true, 'Custodian', 'assets.view', null],
            ['asset-report', 'Asset Report', 'trend', '/asset-report', 740, false, true, 'Custodian', 'assets.view', null],

            ['incidents', 'Incident Management', 'incident', '/incidents', 800, false, true, 'Support', 'incidents.view', 'incidents.open'],
            ['notifications', 'Notifications', 'bell', '/notifications', 810, false, true, 'Support', 'notifications.view', 'notifications.unread'],

            ['access', 'Roles', 'shield', '/access', 900, false, true, 'Administration', 'access.view', null],
            ['zoning', 'Zoning', 'map-pin', '/zoning', 910, false, true, 'Administration', 'company.manage', null],
            ['benefits', 'Benefits', 'badge', '/benefits', 920, false, true, 'Administration', 'company.manage', null],
            ['categories', 'Categories', 'tag', '/categories', 930, false, true, 'Administration', 'company.manage', null],
            ['printables', 'Printables', 'billing', '/printables', 940, false, true, 'Administration', 'company.manage', null],

            ['cargo', 'Cargo', 'shipments', '/cargo', 1100, true, false, 'Driver', 'delivery.view', null],
            ['tracking', 'Tracking', 'map-pin', '/tracking', 1110, true, false, 'Driver', 'gps.write', null],
            ['inspect', 'Inspect', 'clipboard', '/inspect', 1120, true, false, 'Driver', 'inspection.write', null],
            ['more', 'More', 'profile', '/more', 1130, true, false, 'Driver', null, null],

            ['home', 'Home', 'dashboard', '/', 1140, true, false, 'Customer', 'portal.view', null],
            ['requests', 'Deliveries', 'shipments', '/orders', 1150, true, false, 'Customer', 'portal.view', null],
            ['request', 'Request', 'plus', '/request', 1160, true, false, 'Customer', 'portal.request', null],
            ['invoices', 'Invoices', 'billing', '/invoices', 1170, true, false, 'Customer', 'portal.view', null],

            ['jobs', 'Jobs', 'shipments', '/jobs', 1180, true, false, 'Trucker', 'partner.jobs', 'partner.jobs'],
            ['my-trips', 'My Trips', 'route', '/my-trips', 1190, true, false, 'Trucker', 'partner.jobs', null],
            ['wallet', 'Wallet', 'wallet', '/wallet', 1200, true, false, 'Trucker', 'partner.view', null],
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

        NavItem::query()
            ->whereNotIn('key', array_column($items, 0))
            ->delete();
    }
}
