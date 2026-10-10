<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private const PERMISSIONS = [
        ['trips.view', 'View trips', 'Operations', 'See the trip board and any trip on it.'],
        ['trips.manage', 'Book and edit trips', 'Operations', 'Create, confirm, dispatch and complete work.'],
        ['gps.view', 'View GPS', 'Operations', 'The live map and a trip’s tracking history.'],
        ['gps.write', 'Send GPS positions', 'Operations', 'Report position from the handset.'],
        ['dispatch.view', 'View dispatch', 'Operations', 'Dispatch monitoring and arrivals.'],
        ['delivery.view', 'View delivery logs', 'Operations', 'Delivery logs and the delivery report.'],
        ['delivery.write', 'Close out deliveries', 'Operations', 'Hand over and attach proof of delivery.'],
        ['inspection.write', 'Record inspections', 'Operations', 'Pre-trip checks from the vehicle.'],

        ['vehicles.view', 'View vehicles', 'Assets', 'The fleet list and maintenance schedule.'],
        ['vehicles.manage', 'Manage vehicles', 'Assets', 'Add, edit and retire units.'],
        ['drivers.view', 'View drivers', 'Assets', 'The driver roster and availability.'],
        ['drivers.manage', 'Manage drivers', 'Assets', 'Add and edit driver records.'],
        ['truckers.view', 'View truckers', 'Assets', 'The partner roster, their trucks and their wallets.'],
        ['truckers.manage', 'Manage truckers', 'Assets', 'Approve partners, set their rate, assign work and pay them out.'],
        ['fuel.view', 'View fuel', 'Assets', 'Fuel records and the daily budget.'],
        ['fuel.manage', 'Manage fuel', 'Assets', 'Log and correct fuel receipts.'],

        ['finance.view', 'View finance', 'Finance', 'Trip monitoring, profitability and the quarterly summary.'],
        ['finance.manage', 'Manage the ledger', 'Finance', 'Enter and correct daily sheets and units.'],
        ['accounting.view', 'View the books', 'Finance', 'The chart of accounts, the general journal and the general ledger.'],
        ['accounting.manage', 'Post to the books', 'Finance', 'Write, post and void journal entries, and keep the chart of accounts.'],
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

        ['customers.view', 'View customers', 'Business', 'The customer list and their history.'],
        ['customers.manage', 'Manage customers', 'Business', 'Add and edit firms, and issue their logins.'],
        ['billing.view', 'View billing', 'Business', 'Invoices and receivables.'],
        ['billing.manage', 'Manage billing', 'Business', 'Raise invoices and settle payments.'],

        ['hr.view', 'View HR', 'HR', 'The roster, applicants, leave and performance.'],
        ['hr.manage', 'Manage HR', 'HR', 'Register staff, hire applicants and decide requests.'],

        ['access.view', 'View access control', 'Access', 'See roles, positions and who holds what.'],
        ['access.manage', 'Manage access control', 'Access', 'Create roles and change what they reach.'],

        ['company.manage', 'Manage the company', 'Company', 'The company name, contact details and logo.'],

        ['incidents.view', 'View incidents', 'Support', 'The incident log.'],
        ['incidents.manage', 'Manage incidents', 'Support', 'Raise and close out incidents.'],
        ['incidents.write', 'Report incidents', 'Support', 'Report an incident from the road.'],
        ['notifications.view', 'View notifications', 'Support', 'The in-app feed.'],

        ['portal.view', 'Customer portal', 'Portal', 'A firm reading its own deliveries and invoices.'],
        ['portal.request', 'Request a pickup', 'Portal', 'A firm booking its own work.'],

        ['partner.view', 'Trucker app', 'Portal', 'A partner reading their own profile and wallet.'],
        ['partner.jobs', 'Take jobs', 'Portal', 'A partner taking work off the board and running it.'],
        ['crew.trips', 'Run a trucker\'s trips', 'Portal', 'A trucker\'s driver running the trips their trucker handed them.'],
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
