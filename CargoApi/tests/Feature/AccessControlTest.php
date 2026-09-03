<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Roles, positions, and what each actually reaches.
 *
 * The tests that matter are the enforcement ones. Before this, permissions
 * decided only what the sidebar showed — every endpoint was reachable by any
 * account that could sign in, so a driver's token could read the payroll. The
 * matrix below is what stops that quietly coming back.
 *
 * The rest are the ways an access screen locks a business out of its own
 * system: a role deleted while people hold it, the administrator's permissions
 * emptied, a system role removed.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => 'administrator']);

    $this->as = fn (string $roleKey) => User::factory()->create(['role' => $roleKey]);
});

describe('enforcement', function (): void {
    it('lets a role reach its own modules and refuses the rest', function (): void {
        $expected = [
            // role            => [path => status]
            'general-manager' => ['employees' => 200, 'finance/sales' => 200, 'pricing/zones' => 200, 'access/roles' => 200],
            'treasury' => ['employees' => 403, 'finance/sales' => 200, 'pricing/zones' => 200, 'access/roles' => 403],
            'hr-officer' => ['employees' => 200, 'finance/sales' => 403, 'pricing/zones' => 403, 'access/roles' => 200],
            'dispatcher' => ['employees' => 403, 'finance/sales' => 403, 'trips' => 200, 'access/roles' => 403],
            'driver' => ['employees' => 403, 'finance/sales' => 403, 'trips' => 200, 'access/roles' => 403],
        ];

        foreach ($expected as $roleKey => $paths) {
            $user = ($this->as)($roleKey);

            foreach ($paths as $path => $status) {
                expect($this->actingAs($user)->getJson("/api/v1/$path")->getStatusCode())
                    ->toBe($status, "$roleKey should get $status from $path");
            }
        }
    });

    it('separates reading a module from changing it', function (): void {
        $treasury = ($this->as)('treasury');

        // Treasury quotes from the rate card but does not redraw it — that is
        // the accountant's, and it changes what every future run is billed.
        expect($this->actingAs($treasury)->getJson('/api/v1/pricing/zones')->getStatusCode())->toBe(200);

        $this->actingAs($treasury)
            ->postJson('/api/v1/pricing/zones', ['name' => 'Davao', 'code' => 'davao'])
            ->assertForbidden();
    });

    it('keeps an HR officer out of the ledger and away from granting themselves it', function (): void {
        $hr = ($this->as)('hr-officer');

        // They can read the roles list, because registering a hire means
        // offering one — but not change what any role reaches.
        $this->actingAs($hr)->getJson('/api/v1/access/roles')->assertOk();
        $this->actingAs($hr)->postJson('/api/v1/access/roles', ['name' => 'Superuser'])->assertForbidden();
        $this->actingAs($hr)->getJson('/api/v1/ledger')->assertForbidden();
    });

    it('names the permission that was missing', function (): void {
        $response = $this->actingAs(($this->as)('driver'))->getJson('/api/v1/employees');

        expect($response->json('message'))->toContain('hr.view');
    });

    it('lets a driver file the day from the cab, which the office also does', function (): void {
        // One endpoint reached two ways: `finance.write` for the handset,
        // `finance.manage` for the desk. Requiring both would lock out each.
        $this->actingAs(($this->as)('driver'))->getJson('/api/v1/ledger')->assertOk();
        $this->actingAs(($this->as)('accountant'))->getJson('/api/v1/ledger')->assertOk();
    });

    it('turns an unauthenticated call away as unauthorised, not forbidden', function (): void {
        // A 403 would leave a signed-out session staring at "forbidden"; the
        // client's interceptor sends a 401 to the login screen.
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    });
});

describe('roles', function (): void {
    it('adds a role with the permissions it was given', function (): void {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/access/roles', [
            'name' => 'Warehouse Lead',
            'description' => 'The yard.',
            'permissions' => ['trips.view', 'delivery.view'],
        ])->assertCreated();

        expect($response->json('data.key'))->toBe('warehouse-lead');
        expect($response->json('data.permissions'))->toEqualCanonicalizing(['trips.view', 'delivery.view']);
        expect($response->json('data.is_system'))->toBeFalse();
    });

    it('takes effect immediately for anybody holding it', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/access/roles', [
            'name' => 'Warehouse Lead',
            'permissions' => ['trips.view'],
        ])->json('data.id');

        $lead = ($this->as)('warehouse-lead');

        $this->actingAs($lead)->getJson('/api/v1/trips')->assertOk();
        $this->actingAs($lead)->getJson('/api/v1/expenses')->assertForbidden();

        $this->actingAs($this->admin)->patchJson("/api/v1/access/roles/$id", [
            'permissions' => ['trips.view', 'expenses.view'],
        ])->assertOk();

        // Re-resolved rather than reusing the instance: permissions are read
        // from the role once per request and cached on the model for the rest
        // of it, so a long-lived object in a test holds the old list where a
        // real request would build a fresh one.
        $this->actingAs($lead->fresh())->getJson('/api/v1/expenses')->assertOk();
    });

    it('leaves the permissions alone when an edit does not mention them', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/access/roles', [
            'name' => 'Warehouse Lead',
            'permissions' => ['trips.view', 'delivery.view'],
        ])->json('data.id');

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/roles/$id", ['name' => 'Yard Lead'])
            ->assertOk();

        expect($response->json('data.name'))->toBe('Yard Lead');
        expect($response->json('data.permissions'))->toHaveCount(2);
    });

    it('refuses a permission that does not exist, because it would gate nothing', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/access/roles', [
            'name' => 'Warehouse Lead',
            'permissions' => ['warehouse.view'],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    });

    it('will not empty the administrator, which is one click from a locked-out install', function (): void {
        $admin = Role::where('key', 'administrator')->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/roles/{$admin->id}", ['permissions' => []])
            ->assertStatus(422);

        expect($this->admin->fresh()->permissions())->toBe(['*']);
    });

    it('keeps the administrator holding permissions added in a later release', function (): void {
        // `all_permissions` rather than a stored list, so a new permission is
        // not silently missing until somebody notices an unticked box.
        expect($this->admin->hasPermission('a.permission.invented.tomorrow'))->toBeTrue();
    });

    it('deletes a role nobody holds', function (): void {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/v1/access/roles', ['name' => 'Unused'])
            ->json('data.id');

        $this->actingAs($this->admin)->deleteJson("/api/v1/access/roles/$id")->assertNoContent();
    });

    it('refuses to delete a role somebody still holds', function (): void {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/v1/access/roles', ['name' => 'Warehouse Lead'])
            ->json('data.id');

        ($this->as)('warehouse-lead');

        // Deleting would leave that account pointing at nothing — a person who
        // can sign in and see an empty app with no way to explain why.
        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/access/roles/$id")
            ->assertStatus(422);

        expect($response->json('message'))->toContain('One account');
    });

    it('refuses to delete a system role', function (): void {
        $driver = Role::where('key', 'driver')->firstOrFail();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/access/roles/{$driver->id}")
            ->assertStatus(422);
    });

    it('still lets a system role have its permissions tuned', function (): void {
        $driver = Role::where('key', 'driver')->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/roles/{$driver->id}", [
                'permissions' => ['trips.view', 'gps.write', 'notifications.view'],
            ])
            ->assertOk();

        $this->actingAs(($this->as)('driver'))->getJson('/api/v1/inspections')->assertForbidden();
    });

    it('serves the permission vocabulary grouped by module', function (): void {
        $groups = $this->actingAs($this->admin)->getJson('/api/v1/access/permissions')->json('data');

        expect(collect($groups)->pluck('group'))->toContain('Finance', 'HR', 'Access');
    });
});

describe('positions', function (): void {
    it('seeds the job titles a fleet starts with, each with a default role', function (): void {
        $positions = $this->actingAs($this->admin)->getJson('/api/v1/access/positions')->json('data');

        $treasury = collect($positions)->firstWhere('key', 'treasury-officer');

        expect($treasury['name'])->toBe('Treasury Officer');
        expect($treasury['default_role_key'])->toBe('treasury');
    });

    it('adds one the office invents', function (): void {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/access/positions', [
            'name' => 'Yard Marshal',
            'default_role_id' => Role::where('key', 'dispatcher')->firstOrFail()->id,
        ])->assertCreated();

        expect($response->json('data.key'))->toBe('yard-marshal');
        expect($response->json('data.default_role_name'))->toBe('Dispatcher');
    });

    it('allows a position with no default role, for a job that never signs in', function (): void {
        $mechanic = collect($this->actingAs($this->admin)->getJson('/api/v1/access/positions')->json('data'))
            ->firstWhere('key', 'mechanic');

        expect($mechanic['default_role_id'])->toBeNull();
    });

    it('retires a position somebody holds rather than deleting it', function (): void {
        $position = Position::where('key', 'driver')->firstOrFail();

        Employee::create([
            'employee_no' => 'EMP-0001',
            'first_name' => 'Marco',
            'last_name' => 'Reyes',
            'position' => 'Driver',
            'position_id' => $position->id,
            'hired_on' => '2026-03-01',
            'contact' => '0917 555 0101',
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/access/positions/{$position->id}")
            ->assertOk();

        expect($response->json('data.status'))->toBe('inactive');
        expect($response->json('meta.retired'))->toBeTrue();
    });
});

describe('an employee and their access', function (): void {
    beforeEach(function (): void {
        $this->treasuryPost = Position::where('key', 'treasury-officer')->firstOrFail();
    });

    it('takes its job title from the list and copies the label across', function (): void {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position_id' => $this->treasuryPost->id,
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ])->assertCreated();

        expect($response->json('data.position'))->toBe('Treasury Officer');
        expect($response->json('data.position_id'))->toBe($this->treasuryPost->id);
        // What the account form pre-selects, so the office is not asked twice.
        expect($response->json('data.suggested_role'))->toBe('treasury');
    });

    it('still accepts a typed job title, for a role the list has no name for', function (): void {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position' => 'Night Warehouse Supervisor',
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ])->assertCreated();

        expect($response->json('data.position'))->toBe('Night Warehouse Supervisor');
        expect($response->json('data.position_id'))->toBeNull();
    });

    it('insists on one of the two when registering somebody', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ])->assertStatus(422)->assertJsonValidationErrors('position');
    });

    it('gives them a login on a role the office added', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/access/roles', [
            'name' => 'Warehouse Lead',
            'permissions' => ['trips.view'],
        ]);

        $id = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position' => 'Warehouse Lead',
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ])->json('data.id');

        $response = $this->actingAs($this->admin)->postJson("/api/v1/employees/$id/account", [
            'email' => 'ana@cargorush.ph',
            'role' => 'warehouse-lead',
        ])->assertCreated();

        expect($response->json('data.role'))->toBe('warehouse-lead');
        expect($response->json('data.role_label'))->toBe('Warehouse Lead');
    });

    it('refuses a role that does not exist', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position' => 'Clerk',
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ])->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/employees/$id/account", [
            'email' => 'ana@cargorush.ph',
            'role' => 'supreme-overlord',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    });

    it('will not make a member of staff a customer account', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position' => 'Clerk',
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0202',
        ])->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/employees/$id/account", [
            'email' => 'ana@cargorush.ph',
            'role' => 'customer',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    });
});
