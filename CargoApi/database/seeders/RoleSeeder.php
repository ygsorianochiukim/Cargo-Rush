<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Shared\Enums\Role as SystemRole;
use Illuminate\Database\Seeder;

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

    /** @return array<int, array<string, mixed>> */
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
                    'truckers.view', 'truckers.manage',
                    'finance.view', 'finance.manage', 'expenses.view', 'expenses.manage', 'suppliers.view', 'suppliers.manage',
                    'accounting.view', 'accounting.manage',
                    'payroll.view', 'payroll.manage',
                    'sales.view', 'pricing.view', 'pricing.manage',
                    'customers.view', 'customers.manage', 'billing.view', 'billing.manage',
                    'hr.view', 'hr.manage', 'access.view',
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
                    'accounting.view', 'accounting.manage',
                    'payroll.view', 'payroll.manage',
                    'customers.view', 'billing.view', 'billing.manage',
                    'pricing.view', 'pricing.manage', 'expenses.view', 'expenses.manage', 'suppliers.view', 'suppliers.manage',
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
                    'accounting.view',
                    'billing.view', 'billing.manage', 'customers.view',
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
            [
                'key' => SystemRole::TruckerDriver->value,
                'name' => 'Trucker driver',
                'description' => 'Drives for a trucker, and runs only the trips that trucker hands them.',
                'system' => true,
                'permissions' => [
                    'crew.trips', 'gps.write', 'delivery.view', 'delivery.write',
                    'notifications.view',
                ],
            ],
        ];
    }
}
