<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Shared\Enums\Role as SystemRole;
use Illuminate\Database\Seeder;

/**
 * The roles an install starts with, and what each one reaches.
 *
 * The five from `Shared\Enums\Role` are seeded with exactly the permissions
 * that enum already granted, so an install that runs this changes nothing about
 * who can do what. Three more are added because every fleet asks for them and
 * none of them justified a code change:
 *
 *   General Manager — the whole business, except the access screens. That is
 *   the one distinction between them and the administrator, and it is a real
 *   one: the GM runs the company, the administrator hands out keys.
 *
 *   Treasury — money moving in and out. Bills, collects, files spend. Cannot
 *   redraw the rate card, which is the accountant's.
 *
 *   HR Officer — the roster and the hiring, and `drivers.view` so a new hire
 *   can be linked to the operational record. Deliberately no `access.manage`:
 *   whoever runs HR should not be able to grant themselves the ledger.
 *
 * Permissions are synced on every run because they are this file's definition
 * of the role. What is *not* touched is a role the office added itself, or
 * anything about who holds which role.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::pluck('id', 'key');

        foreach ($this->definitions() as $index => $definition) {
            /** @var Role $role */
            $role = Role::withTrashed()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => $definition['system'],
                    'all_permissions' => $definition['all'] ?? false,
                    'position' => ($index + 1) * 10,
                    'deleted_at' => null,
                ],
            );

            // The administrator holds `*` rather than a list, so there is
            // nothing to sync — and syncing a snapshot would be wrong the next
            // time a permission is added.
            if ($role->all_permissions) {
                continue;
            }

            $role->permissions()->sync(
                collect($definition['permissions'])
                    ->map(static fn (string $key) => $permissions[$key] ?? null)
                    ->filter()
                    ->all(),
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => SystemRole::Administrator->value,
                'name' => 'Administrator',
                'description' => 'Everything, including who can reach what.',
                'system' => true,
                'all' => true,
                'permissions' => [],
            ],
            [
                'key' => 'general-manager',
                'name' => 'General Manager',
                'description' => 'The whole business. Cannot change access.',
                'system' => false,
                'permissions' => [
                    'trips.view', 'trips.manage', 'gps.view', 'dispatch.view',
                    'delivery.view', 'vehicles.view', 'vehicles.manage',
                    'drivers.view', 'drivers.manage', 'fuel.view', 'fuel.manage',
                    // Partners, both halves. Approving one and setting their
                    // rate are commercial decisions about who hauls for the
                    // firm, which is the GM's job description.
                    'truckers.view', 'truckers.manage',
                    'finance.view', 'finance.manage', 'expenses.view', 'expenses.manage', 'suppliers.view', 'suppliers.manage',
                    // The books, both halves: a GM runs the business and signs
                    // off what the statements say — and payroll, which is the
                    // largest cheque the firm writes.
                    'accounting.view', 'accounting.manage',
                    'payroll.view', 'payroll.manage',
                    'sales.view', 'pricing.view', 'pricing.manage',
                    'customers.view', 'customers.manage', 'billing.view', 'billing.manage',
                    'hr.view', 'hr.manage', 'access.view',
                    // The company's own identity, but still not `access.manage`
                    // — the GM runs the company, the administrator hands out
                    // keys, and that is the one line between them.
                    'company.manage',
                    'incidents.view', 'incidents.manage', 'notifications.view',
                ],
            ],
            [
                'key' => SystemRole::Dispatcher->value,
                'name' => 'Dispatcher',
                'description' => 'The road: booking work, crews and units.',
                'system' => false,
                'permissions' => [
                    'trips.view', 'trips.manage', 'gps.view', 'dispatch.view',
                    'delivery.view', 'vehicles.view', 'drivers.view',
                    /**
                     * Sees who hauls for the firm; does not decide who does.
                     *
                     * The dispatcher needs the roster to know a run has been
                     * taken and by whom. Handing a contractor the work is
                     * `truckers.manage`, deliberately — assigning the fleet's
                     * own drivers costs a rota, and assigning a partner commits
                     * the firm to paying somebody outside it.
                     */
                    'truckers.view',
                    'incidents.view', 'incidents.manage', 'notifications.view',
                ],
            ],
            [
                'key' => SystemRole::Accountant->value,
                'name' => 'Accountant',
                'description' => 'The books, the rate card and what a haul is charged.',
                'system' => false,
                'permissions' => [
                    'trips.view', 'fuel.view', 'fuel.manage', 'finance.view', 'finance.manage',
                    // Whose job this is. The general journal and the general
                    // ledger are the accountant's book before they are
                    // anybody's report — and payroll is theirs to run.
                    'accounting.view', 'accounting.manage',
                    'payroll.view', 'payroll.manage',
                    'customers.view', 'billing.view', 'billing.manage',
                    'pricing.view', 'pricing.manage', 'expenses.view', 'expenses.manage', 'suppliers.view', 'suppliers.manage',
                    // Reads the partner wallets. What a contractor is owed is
                    // a liability of the firm, and the accountant answers for
                    // the figure whether or not they are the one who pays it.
                    'truckers.view',
                    'sales.view', 'notifications.view',
                ],
            ],
            [
                'key' => 'treasury',
                'name' => 'Treasury',
                'description' => 'Money in and out. Bills, collects and files spend.',
                'system' => false,
                'permissions' => [
                    'finance.view', 'expenses.view', 'expenses.manage', 'suppliers.view', 'suppliers.manage', 'sales.view',
                    // Reads the books, does not post to them. Treasury moves
                    // money and files spend; what the entry says about it is
                    // the accountant's call, and an install where both could
                    // post has nobody left to check the other.
                    'accounting.view',
                    'billing.view', 'billing.manage', 'customers.view',
                    // Money out includes paying the partners, so treasury both
                    // reads the wallets and settles them.
                    'truckers.view', 'truckers.manage',
                    'pricing.view', 'notifications.view',
                ],
            ],
            [
                'key' => 'hr-officer',
                'name' => 'HR Officer',
                'description' => 'The roster, hiring, leave and performance.',
                'system' => false,
                'permissions' => [
                    'hr.view', 'hr.manage', 'drivers.view', 'notifications.view',
                    /**
                     * Reads payroll, does not run it.
                     *
                     * HR owns the roster and the salaries on it, and needs to
                     * see what people were paid to answer for it. Approving a
                     * run and posting it to the books is the money side, and
                     * whoever keeps the roster should not also be the one who
                     * signs off the cheque.
                     */
                    'payroll.view',
                ],
            ],
            [
                'key' => SystemRole::Driver->value,
                'name' => 'Driver',
                'description' => 'The handset: their own work, on the road.',
                'system' => true,
                'permissions' => [
                    'trips.view', 'gps.write', 'delivery.view', 'delivery.write',
                    'inspection.write', 'incidents.write', 'finance.write',
                    'notifications.view',
                ],
            ],
            [
                'key' => SystemRole::Customer->value,
                'name' => 'Customer',
                'description' => 'A firm booking its own work and reading its own money.',
                'system' => true,
                'permissions' => ['portal.view', 'portal.request', 'notifications.view'],
            ],
            [
                'key' => SystemRole::Trucker->value,
                'name' => 'Trucker',
                'description' => 'An owner-operator taking work and watching their wallet.',
                'system' => true,
                'permissions' => [
                    'partner.view', 'partner.jobs', 'gps.write',
                    'delivery.view', 'delivery.write', 'incidents.write',
                    'notifications.view',
                ],
            ],
        ];
    }
}
