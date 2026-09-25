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
 *
 * ## `order` is global, and a group is a run of it
 *
 * The API sorts every row by `order` and the sidebar then walks that list,
 * starting a new heading whenever the group name changes (`IdentityService.
 * navGroups`). So a group is **a contiguous run of orders**, not a label the
 * client gathers by — and two groups whose numbers interleave do not merge,
 * they each render twice with the intruder wedged between the halves.
 *
 * That is not hypothetical. `customers` sat at 90 and `journal` at 90, which
 * put Customer Management between Financial Statements and General Journal and
 * printed this:
 *
 *     Operations | Assets | Finance | Business | Finance | Business | HR | Support
 *
 * Hence the bands below: **one hundred per group, in tens**. A new module goes
 * in its group's band and there is room for nine of them before anybody has to
 * think about it. Nothing outside a band, nothing sharing a number —
 * `NavigationTest` fails if either happens, because the failure on screen looks
 * like a styling bug and nobody goes looking in a seeder for it.
 *
 * ## The handset bands are their own thing
 *
 * The driver, customer and trucker tabs are numbered from 900 and their order
 * does not matter: `cargoApp` iterates its own hardcoded tab list and takes
 * only the label, icon and badge from here — "a tab bar's order is also its
 * muscle memory". They are numbered anyway so that nothing in this table shares
 * an `order` with anything else.
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            /*
             * Operations — the day being run. What a dispatcher opens.
             */
            ['dashboard', 'Dashboard', 'dashboard', '/dashboard', 100, true, true, 'Operations', null, null],
            ['trips', 'Trip Management', 'route', '/trips', 110, true, true, 'Operations', 'trips.view', 'trips.requests'],
            ['dispatch', 'Dispatch Monitoring', 'dispatch', '/dispatch', 120, false, true, 'Operations', 'dispatch.view', null],
            ['delivery-logs', 'Delivery Logs', 'clipboard', '/delivery-logs', 130, true, true, 'Operations', 'delivery.view', null],
            // Last of the five rather than second: the map answers "where is it
            // now", which is the question asked after the run is on the board,
            // not before it.
            ['gps', 'GPS Dashboard', 'map-pin', '/gps', 140, true, true, 'Operations', 'gps.view', null],

            /*
             * Fleet — what and who the work goes out on.
             *
             * Named for the thing rather than for the accounting category it
             * used to sit in ("Assets"): nobody at a yard says they are going
             * to look at the assets. Truckers are here beside the drivers
             * because that is what the desk is asking when it opens either one
             * — who can move this load. The badge counts registrations nobody
             * has vetted; somebody is sitting in a waiting room until they do.
             */
            ['vehicles', 'Vehicle Management', 'fleet', '/vehicles', 200, false, true, 'Fleet', 'vehicles.view', null],
            ['drivers', 'Drivers Management', 'profile', '/drivers', 210, false, true, 'Fleet', 'drivers.view', null],
            ['truckers', 'Truckers', 'fleet', '/truckers', 220, false, true, 'Fleet', 'truckers.view', 'truckers.pending'],
            ['fuel', 'Fuel Expense', 'fuel', '/fuel', 230, false, true, 'Fleet', 'fuel.view', null],

            /*
             * Sales & Billing — money owed, in both directions.
             *
             * In the order the work flows: a customer, the card their run is
             * priced off, the invoice it raises, and then the two screens for
             * money going the other way. Payables and Other Expenses moved here
             * from Finance because they answer the same question as the invoice
             * beside them — who owes what — and because Payables reads the
             * pending expense rows directly.
             */
            ['customers', 'Customer Management', 'customers', '/customers', 300, false, true, 'Sales & Billing', 'customers.view', null],
            ['pricing', 'Rate Card', 'tag', '/pricing', 310, false, true, 'Sales & Billing', 'pricing.view', null],
            ['billing', 'Billing & Invoice', 'billing', '/billing', 320, false, true, 'Sales & Billing', 'billing.view', null],
            // What the fleet owes, gathered from the four places it was
            // scattered across — partners, hired trucks, suppliers and spend.
            // Read-only; every line links to the screen that settles it.
            ['payables', 'Payables', 'billing', '/payables', 330, false, true, 'Sales & Billing', 'finance.view', null],
            ['expenses', 'Other Expenses', 'wallet', '/expenses', 340, false, true, 'Sales & Billing', 'expenses.view', null],
            // Who the fleet buys from. Beside the spend rather than under
            // Fleet, because an expense, a service and a bill all pick from
            // this one list and only one of those three is a yard job.
            ['suppliers', 'Suppliers', 'customers', '/suppliers', 350, false, true, 'Sales & Billing', 'suppliers.view', null],
            /**
             * What keeping the trucks on the road costs.
             *
             * Here rather than under Fleet, and gated on `expenses.view` rather
             * than on `vehicles.view`, because both answer the same question:
             * who files it. A garage's invoice is filed by whoever files the
             * fleet's spend — the same person, at the same desk, in the same
             * hour as the tarpaulins and the tolls beside it. The yard keeps
             * its own view of a unit's servicing on the vehicle's screen.
             *
             * It is not Other Expenses, and must not become it again: a service
             * is money that belongs to **one truck**, and it lands in that
             * unit's Maintenance column on the daily sheet. Other Expenses is
             * for what belongs to the period and to no unit.
             */
            ['maintenance', 'Truck Maintenance', 'fleet', '/maintenance', 360, false, true, 'Sales & Billing', 'expenses.view', null],

            /*
             * Reports — the transcribed workbook.
             *
             * The four screens that read the daily sheets back: one unit's
             * rows, ten days, a quarter, and sales over time. They were inside
             * a ten-item Finance group doing three unrelated jobs, which is
             * what made that list unreadable.
             */
            ['monitoring', 'Trip Monitoring', 'clipboard', '/monitoring', 400, false, true, 'Reports', 'finance.view', null],
            ['profitability', 'Profitability', 'gauge', '/profitability', 410, false, true, 'Reports', 'finance.view', null],
            ['summary', 'Quarterly Summary', 'calendar', '/summary', 420, false, true, 'Reports', 'finance.view', null],
            ['sales', 'Sales Report', 'trend', '/sales', 430, false, true, 'Reports', 'sales.view', null],

            /*
             * Accounting — the books proper.
             *
             * The statements first: they are what the books are *for*, and the
             * journal and the ledger are how they get there. The chart last,
             * because it is the thing you set up once and then stop opening.
             */
            ['statements', 'Financial Statements', 'trend', '/statements', 500, false, true, 'Accounting', 'accounting.view', null],
            ['journal', 'General Journal', 'clipboard', '/journal', 510, false, true, 'Accounting', 'accounting.view', null],
            ['ledger', 'General Ledger', 'gauge', '/ledger', 520, false, true, 'Accounting', 'accounting.view', null],
            ['accounts', 'Chart of Accounts', 'tag', '/accounts', 530, false, true, 'Accounting', 'accounting.view', null],

            /*
             * People — the roster.
             *
             * Two of these carry badges, because both count something sitting
             * on somebody's desk: a CV nobody has read, and a leave request
             * nobody has decided.
             *
             * Payroll sits with the people rather than with the money: it is
             * read by whoever answers for the roster, and run by whoever signs
             * the cheque. Its own permission keeps those apart.
             */
            ['employees', 'Employees', 'badge', '/employees', 600, false, true, 'People', 'hr.view', null],
            ['applicants', 'Applicants', 'inbox', '/applicants', 610, false, true, 'People', 'hr.view', 'applicants.open'],
            ['time-off', 'Leave & Undertime', 'calendar', '/time-off', 620, false, true, 'People', 'hr.view', 'timeoff.open'],
            ['performance', 'Performance', 'gauge', '/performance', 630, false, true, 'People', 'hr.view', null],
            ['payroll', 'Payroll', 'wallet', '/payroll', 640, false, true, 'People', 'payroll.view', null],

            /*
             * Support.
             */
            ['incidents', 'Incident Management', 'incident', '/incidents', 700, false, true, 'Support', 'incidents.view', 'incidents.open'],
            ['notifications', 'Notifications', 'bell', '/notifications', 710, false, true, 'Support', 'notifications.view', 'notifications.unread'],

            /*
             * Administration — the screen that configures the install.
             *
             * Access Control was under HR, and it never belonged there. What it
             * holds is the company's own record, where the yard is, when
             * payroll closes, what the firm charges, the roles and what each
             * one reaches. An HR officer runs the roster; they do not thereby
             * get to grant themselves the ledger, and the permission on this
             * row has always said so. The group now says so too.
             *
             * One item, deliberately. A heading over a single row is cheaper
             * than a settings screen filed under the wrong job.
             */
            ['access', 'Access Control', 'shield', '/access', 800, false, true, 'Administration', 'access.view', null],

            /**
             * Salary Structure is gone, and this note is why.
             *
             * The allowances-and-deductions catalogue still exists and payroll
             * still reads it. What went is the screen for keeping it, which was
             * a second pay system standing beside the first: a catalogue to
             * learn and an assignment to make before an office could put ₱500
             * on one payslip.
             *
             * Both halves moved to where the work happens. A recurring
             * allowance is assigned on the employee, on the Employees screen. A
             * one-off deduction — a uniform, a breakage, a cash advance — is
             * added on the pay run, on the payslip it belongs to, and carries
             * across a rebuild.
             *
             * Its row outlived the screen by several releases, because this
             * seeder only ever wrote rows and never removed one: `updateOrCreate`
             * left the old row sitting in the sidebar pointing at a route that
             * no longer exists. The prune below is what fixes that class of
             * bug, not just this instance of it.
             */

            // Mobile-only tabs. `web: false` keeps them out of the sidebar —
            // the driver app's five tabs are a different product, not a subset
            // rendered small (DESIGN.md section 5.2). Numbered from 900 purely
            // so nothing in this table shares an `order`; `cargoApp` sorts by
            // its own hardcoded list and ignores these.
            ['cargo', 'Cargo', 'shipments', '/cargo', 900, true, false, 'Driver', 'delivery.view', null],
            ['tracking', 'Tracking', 'map-pin', '/tracking', 910, true, false, 'Driver', 'gps.write', null],
            ['inspect', 'Inspect', 'clipboard', '/inspect', 920, true, false, 'Driver', 'inspection.write', null],
            ['more', 'More', 'profile', '/more', 930, true, false, 'Driver', null, null],

            // Customer-only tabs. `web: false` for the same reason the driver's
            // are: `cargoApp` is one app that opens on a different set of tabs
            // depending on who signed in, and neither set belongs in the
            // back-office sidebar. The permissions are what keep the two sets
            // apart — a customer holds `portal.*` and nothing else, so the
            // driver's tabs cannot come back for them and theirs cannot come
            // back for a driver.
            ['home', 'Home', 'dashboard', '/', 940, true, false, 'Customer', 'portal.view', null],
            ['requests', 'Deliveries', 'shipments', '/orders', 950, true, false, 'Customer', 'portal.view', null],
            ['request', 'Request', 'plus', '/request', 960, true, false, 'Customer', 'portal.request', null],
            ['invoices', 'Invoices', 'billing', '/invoices', 970, true, false, 'Customer', 'portal.view', null],

            /**
             * Trucker-only tabs — the third set `cargoApp` can open on.
             *
             * `web: false` like the other two handset sets, and gated on
             * `partner.*`, which only the trucker role holds. That is what
             * keeps the three apart: a partner cannot be shown the driver's
             * Inspect tab or the customer's Request tab, because they hold
             * neither permission, and neither of those can be shown the board
             * or the wallet.
             *
             * `jobs` carries a badge for the same reason Applicants does: it
             * counts work sitting there waiting for somebody to decide, and a
             * partner who has to open the tab to find out is a partner who
             * stops opening it.
             */
            ['jobs', 'Jobs', 'shipments', '/jobs', 980, true, false, 'Trucker', 'partner.jobs', 'partner.jobs'],
            ['my-trips', 'My Trips', 'route', '/my-trips', 990, true, false, 'Trucker', 'partner.jobs', null],
            ['wallet', 'Wallet', 'wallet', '/wallet', 1000, true, false, 'Trucker', 'partner.view', null],
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

        /**
         * A module this file no longer defines is a module that no longer
         * exists.
         *
         * `updateOrCreate` writes and never removes, so every screen ever
         * deleted left its row behind — and a nav row is a link. Salary
         * Structure sat in the sidebar for several releases pointing at a route
         * that had been taken out, which is a 404 somebody finds by clicking it.
         *
         * Deleting here is safe because there is no way to *add* a nav row
         * except by editing this array: nothing in the application writes to
         * `nav_items`, so anything in the table that is not in the list above
         * is something this file used to say and stopped saying.
         *
         * The module assignments in `user_modules` point at these keys by
         * string and are left alone. They narrow a person's sidebar to a set of
         * keys, so one naming a module that no longer exists simply matches
         * nothing — which is what it should do.
         */
        NavItem::query()
            ->whereNotIn('key', array_column($items, 0))
            ->delete();
    }
}
