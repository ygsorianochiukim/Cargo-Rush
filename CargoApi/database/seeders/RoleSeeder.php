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
                    'finance.view', 'finance.manage', 'expenses.view', 'expenses.manage',
                    'sales.view', 'pricing.view', 'pricing.manage',
                    'customers.view', 'customers.manage', 'billing.view', 'billing.manage',
                    'hr.view', 'hr.manage', 'access.view',
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
                    'customers.view', 'billing.view', 'billing.manage',
                    'pricing.view', 'pricing.manage', 'expenses.view', 'expenses.manage',
                    'sales.view', 'notifications.view',
                ],
            ],
            [
                'key' => 'treasury',
                'name' => 'Treasury',
                'description' => 'Money in and out. Bills, collects and files spend.',
                'system' => false,
                'permissions' => [
                    'finance.view', 'expenses.view', 'expenses.manage', 'sales.view',
                    'billing.view', 'billing.manage', 'customers.view',
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
                ],
            ],
            [
                'key' => SystemRole::Driver->value,
                'name' => 'Driver',
                'description' => 'The handset: their own work, on the road.',
                'system' => true,
                'permissions' => [
                    'trips.view', 'gps.write', 'delivery.view', 'delivery.write',
                    'inspection.write', 'finance.write', 'notifications.view',
                ],
            ],
            [
                'key' => SystemRole::Customer->value,
                'name' => 'Customer',
                'description' => 'A firm booking its own work and reading its own money.',
                'system' => true,
                'permissions' => ['portal.view', 'portal.request', 'notifications.view'],
            ],
        ];
    }
}
