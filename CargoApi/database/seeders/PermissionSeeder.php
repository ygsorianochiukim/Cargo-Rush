<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * The permission vocabulary.
 *
 * Developer-owned, unlike roles and positions: a permission is only real if
 * code checks for it, so one invented in the UI would gate nothing. Adding a
 * permission means adding it here *and* naming it on the route that should
 * require it — a row on its own does nothing.
 *
 * `updateOrCreate`, not `firstOrCreate`, and the difference matters on the
 * second run: these rows are this file's, so a wording fix in a later release
 * reaches installs that already exist. What is *not* touched is which roles
 * hold them — that is the office's, and it lives in the pivot.
 */
class PermissionSeeder extends Seeder
{
    /**
     * key, name, group, description
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const PERMISSIONS = [
        // Operations
        ['trips.view', 'View trips', 'Operations', 'See the trip board and any trip on it.'],
        ['trips.manage', 'Book and edit trips', 'Operations', 'Create, confirm, dispatch and complete work.'],
        ['gps.view', 'View GPS', 'Operations', 'The live map and a trip’s tracking history.'],
        ['gps.write', 'Send GPS positions', 'Operations', 'Report position from the handset.'],
        ['dispatch.view', 'View dispatch', 'Operations', 'Dispatch monitoring and arrivals.'],
        ['delivery.view', 'View delivery logs', 'Operations', 'Delivery logs and the delivery report.'],
        ['delivery.write', 'Close out deliveries', 'Operations', 'Hand over and attach proof of delivery.'],
        ['inspection.write', 'Record inspections', 'Operations', 'Pre-trip checks from the vehicle.'],

        // Assets
        ['vehicles.view', 'View vehicles', 'Assets', 'The fleet list and maintenance schedule.'],
        ['vehicles.manage', 'Manage vehicles', 'Assets', 'Add, edit and retire units.'],
        ['drivers.view', 'View drivers', 'Assets', 'The driver roster and availability.'],
        ['drivers.manage', 'Manage drivers', 'Assets', 'Add and edit driver records.'],
        /**
         * Partner truckers, and a line between reading the roster and acting
         * on it.
         *
         * `manage` is the heavier half of any pair in this list. Approving a
         * registration hands a stranger a customer's cargo; setting a rate
         * decides what every future run of theirs splits at; a payout moves
         * money out of the business. Whoever answers the phone can see who
         * hauls and what they are owed without being able to do any of the
         * three.
         */
        ['truckers.view', 'View truckers', 'Assets', 'The partner roster, their trucks and their wallets.'],
        ['truckers.manage', 'Manage truckers', 'Assets', 'Approve partners, set their rate, assign work and pay them out.'],
        ['fuel.view', 'View fuel', 'Assets', 'Fuel records and the daily budget.'],
        ['fuel.manage', 'Manage fuel', 'Assets', 'Log and correct fuel receipts.'],

        // Finance
        ['finance.view', 'View finance', 'Finance', 'Trip monitoring, profitability and the quarterly summary.'],
        ['finance.manage', 'Manage the ledger', 'Finance', 'Enter and correct daily sheets and units.'],
        /**
         * The books proper, and a deliberate line between reading them and
         * posting to them. Reading the general journal and the ledger is a
         * management job; writing an entry decides what every statement
         * afterwards says, and voiding one is a correction to the record.
         */
        ['accounting.view', 'View the books', 'Finance', 'The chart of accounts, the general journal and the general ledger.'],
        ['accounting.manage', 'Post to the books', 'Finance', 'Write, post and void journal entries, and keep the chart of accounts.'],
        /**
         * Payroll, and a line between reading it and running it.
         *
         * A payslip is somebody's private business, so seeing the runs is its
         * own permission rather than part of `hr.view` — the roster and the pay
         * are different rooms. Running one moves money and posts to the books,
         * which is why approving and paying are the tighter half.
         */
        ['payroll.view', 'View payroll', 'HR', 'Pay runs and payslips.'],
        ['payroll.manage', 'Run payroll', 'HR', 'Build, approve and pay a payroll run.'],
        ['finance.write', 'Record from the cab', 'Finance', 'A driver filing the day’s figures from the handset.'],
        ['expenses.view', 'View expenses', 'Finance', 'Categorised spend and the expense report.'],
        ['expenses.manage', 'Manage expenses', 'Finance', 'File, approve and categorise spend.'],
        ['suppliers.view', 'View suppliers', 'Finance', 'Who the fleet buys from, and what has been spent there.'],
        ['suppliers.manage', 'Manage suppliers', 'Finance', 'Add, rename and retire the firms the fleet buys from.'],
        ['sales.view', 'View sales', 'Finance', 'Daily, weekly and monthly takings.'],
        ['pricing.view', 'View the rate card', 'Finance', 'Zones, brackets and the diesel adjustment.'],
        ['pricing.manage', 'Manage the rate card', 'Finance', 'Change what every future run is charged.'],

        // Business
        ['customers.view', 'View customers', 'Business', 'The customer list and their history.'],
        ['customers.manage', 'Manage customers', 'Business', 'Add and edit firms, and issue their logins.'],
        ['billing.view', 'View billing', 'Business', 'Invoices and receivables.'],
        ['billing.manage', 'Manage billing', 'Business', 'Raise invoices and settle payments.'],

        // HR
        ['hr.view', 'View HR', 'HR', 'The roster, applicants, leave and performance.'],
        ['hr.manage', 'Manage HR', 'HR', 'Register staff, hire applicants and decide requests.'],

        // Access — the RBAC screens themselves. Separated from `hr.manage` so
        // an HR officer can run the roster without being able to grant
        // themselves the finance module.
        ['access.view', 'View access control', 'Access', 'See roles, positions and who holds what.'],
        ['access.manage', 'Manage access control', 'Access', 'Create roles and change what they reach.'],

        // The company's own identity, kept apart from access control. Editing
        // the logo is not handing out keys, and somebody trusted to keep the
        // company's details right is not thereby trusted to grant themselves
        // the ledger.
        ['company.manage', 'Manage the company', 'Company', 'The company name, contact details and logo.'],

        // Support
        ['incidents.view', 'View incidents', 'Support', 'The incident log.'],
        ['incidents.manage', 'Manage incidents', 'Support', 'Raise and close out incidents.'],
        // The driver's half, and deliberately not `manage`: report what
        // happened on your own run, from the road. The log itself, editing a
        // write-up and closing one out are the office's.
        ['incidents.write', 'Report incidents', 'Support', 'Report an incident from the road.'],
        ['notifications.view', 'View notifications', 'Support', 'The in-app feed.'],

        // Customer portal
        ['portal.view', 'Customer portal', 'Portal', 'A firm reading its own deliveries and invoices.'],
        ['portal.request', 'Request a pickup', 'Portal', 'A firm booking its own work.'],

        /**
         * The partner's own app, and the mirror of the two portal permissions
         * above.
         *
         * `partner.view` is their record, their switch and their wallet — the
         * things a partner may always reach, including one the office has just
         * stood down, because somebody who cannot go offline is somebody the
         * app is working against.
         *
         * `partner.jobs` is the board and the runs on it. Its own permission so
         * a fleet can keep somebody on the roster and off the board without
         * suspending them outright.
         */
        ['partner.view', 'Trucker app', 'Portal', 'A partner reading their own profile and wallet.'],
        ['partner.jobs', 'Take jobs', 'Portal', 'A partner taking work off the board and running it.'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $index => [$key, $name, $group, $description]) {
            Permission::updateOrCreate(['key' => $key], [
                'name' => $name,
                'group' => $group,
                'description' => $description,
                'position' => ($index + 1) * 10,
            ]);
        }
    }
}
