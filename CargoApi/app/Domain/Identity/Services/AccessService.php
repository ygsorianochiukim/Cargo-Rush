<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Repositories\AccessRepository;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who can reach what.
 *
 * The rules here are all about not letting somebody lock the business out of
 * its own system, which is the failure mode every access screen has:
 *
 *   A role nobody holds can be deleted. A role somebody holds cannot, because
 *   deleting it would leave those accounts pointing at nothing — and an account
 *   with no role resolves to no permissions, which is a person who can sign in
 *   and see an empty app with no way to explain why.
 *
 *   A system role cannot be deleted or renamed by key at all. `driver` and
 *   `customer` are the two the product itself depends on: the handset opens on
 *   a different set of tabs for each. Its *permissions* stay editable, because
 *   tuning what a driver reaches is a legitimate thing to want.
 *
 *   The administrator's permission list cannot be emptied. `all_permissions` is
 *   what keeps it holding permissions added in later releases, and turning it
 *   off would be one click away from an install nobody can administer.
 */
class AccessService
{
    public function __construct(private readonly AccessRepository $access) {}

    /* --------------------------------------------------------------- Roles */

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRole(array $attributes): Role
    {
        return DB::transaction(function () use ($attributes): Role {
            $role = Role::create([
                'key' => $this->uniqueKey(
                    Str::slug((string) ($attributes['key'] ?? $attributes['name'])),
                ),
                'name' => (string) $attributes['name'],
                'description' => $attributes['description'] ?? null,
                // Only the seeders make system roles. A role the office adds is
                // one it can also remove.
                'is_system' => false,
                'all_permissions' => false,
                'position' => (int) ($attributes['position'] ?? 500),
                'status' => $attributes['status'] ?? StatusValue::Active->value,
            ]);

            $this->syncPermissions($role, $attributes['permissions'] ?? []);

            return $role->refresh()->load('permissions');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRole(Role $role, array $attributes): Role
    {
        return DB::transaction(function () use ($role, $attributes): Role {
            $role->update(array_intersect_key(
                $attributes,
                array_flip(['name', 'description', 'position', 'status']),
            ));

            // Absent means "not part of this edit" — renaming a role must not
            // be read as an instruction to strip it of everything.
            if (array_key_exists('permissions', $attributes)) {
                $this->syncPermissions($role, $attributes['permissions']);
            }

            return $role->refresh()->load('permissions');
        });
    }

    /**
     * Remove a role, if nothing depends on it.
     *
     * Refused rather than cascading: reassigning people is a decision, and
     * doing it silently would move somebody's access without anybody choosing
     * where to.
     */
    public function deleteRole(Role $role): void
    {
        abort_if(
            $role->is_system,
            422,
            'This role is part of the app itself and cannot be deleted. Switch it off or change what it reaches instead.',
        );

        $holders = $this->access->usersHolding($role->key);

        abort_if(
            $holders > 0,
            422,
            $holders === 1
                ? 'One account still holds this role. Move them to another role first.'
                : "$holders accounts still hold this role. Move them to another role first.",
        );

        // Any position defaulting to it is left pointing at nothing rather than
        // silently re-pointed — `nullOnDelete` on the column, and the position
        // screen then shows it as having no default.
        $role->delete();
    }

    /**
     * Tick and untick permissions on a role.
     *
     * Keys, not ids: the client works from the permission vocabulary, and an id
     * is a detail of how this install stored it.
     *
     * @param  array<int, string>  $keys
     */
    private function syncPermissions(Role $role, array $keys): void
    {
        abort_if(
            $role->all_permissions,
            422,
            'This role holds every permission, including ones added in future releases. Its list is not editable.',
        );

        $ids = Permission::whereIn('key', array_values(array_filter($keys)))->pluck('id')->all();

        $role->permissions()->sync($ids);
    }

    /* ----------------------------------------------------------- Positions */

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPosition(array $attributes): Position
    {
        $position = Position::create([
            'key' => $this->uniquePositionKey(
                Str::slug((string) ($attributes['key'] ?? $attributes['name'])),
            ),
            'name' => (string) $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'default_role_id' => $attributes['default_role_id'] ?? null,
            'position' => (int) ($attributes['position'] ?? 500),
            'status' => $attributes['status'] ?? StatusValue::Active->value,
        ]);

        return $position->refresh()->load('defaultRole');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updatePosition(Position $position, array $attributes): Position
    {
        $position->update(array_intersect_key(
            $attributes,
            array_flip(['name', 'description', 'default_role_id', 'position', 'status']),
        ));

        return $position->refresh()->load('defaultRole');
    }

    /**
     * Remove a position, or retire it if anybody holds it.
     *
     * The same trade `ExpenseService` makes with a category that has spend
     * against it: deleting would take the link with it, and the roster would
     * quietly lose what those people do.
     *
     * @return bool true when it was deleted, false when it was retired instead
     */
    public function deletePosition(Position $position): bool
    {
        if ($position->employees()->exists()) {
            $position->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $position->delete();

        return true;
    }

    /** "treasury", then "treasury-2" — a name somebody reuses must not collide. */
    private function uniqueKey(string $base): string
    {
        $base = $base === '' ? 'role' : $base;
        $key = $base;
        $suffix = 2;

        while (Role::withTrashed()->where('key', $key)->exists()) {
            $key = "$base-".$suffix++;
        }

        return $key;
    }

    private function uniquePositionKey(string $base): string
    {
        $base = $base === '' ? 'position' : $base;
        $key = $base;
        $suffix = 2;

        while (Position::withTrashed()->where('key', $key)->exists()) {
            $key = "$base-".$suffix++;
        }

        return $key;
    }
}
