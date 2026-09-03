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
        ['fuel.view', 'View fuel', 'Assets', 'Fuel records and the daily budget.'],
        ['fuel.manage', 'Manage fuel', 'Assets', 'Log and correct fuel receipts.'],

        // Finance
        ['finance.view', 'View finance', 'Finance', 'Trip monitoring, profitability and the quarterly summary.'],
        ['finance.manage', 'Manage the ledger', 'Finance', 'Enter and correct daily sheets and units.'],
        ['finance.write', 'Record from the cab', 'Finance', 'A driver filing the day’s figures from the handset.'],
        ['expenses.view', 'View expenses', 'Finance', 'Categorised spend and the expense report.'],
        ['expenses.manage', 'Manage expenses', 'Finance', 'File, approve and categorise spend.'],
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

        // Support
        ['incidents.view', 'View incidents', 'Support', 'The incident log.'],
        ['incidents.manage', 'Manage incidents', 'Support', 'Raise and close out incidents.'],
        ['notifications.view', 'View notifications', 'Support', 'The in-app feed.'],

        // Customer portal
        ['portal.view', 'Customer portal', 'Portal', 'A firm reading its own deliveries and invoices.'],
        ['portal.request', 'Request a pickup', 'Portal', 'A firm booking its own work.'],
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
