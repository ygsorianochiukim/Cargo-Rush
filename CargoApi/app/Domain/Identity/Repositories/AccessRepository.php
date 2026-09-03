<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repositories;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Roles, positions and the permission vocabulary.
 *
 * One repository for the three because they are only ever read together: the
 * access screen needs every role with its permissions, the whole vocabulary to
 * draw the matrix, and the positions that point at those roles. Three
 * repositories would mean the controller doing the joining.
 */
class AccessRepository
{
    /** @return Collection<int, Role> */
    public function roles(bool $activeOnly = false): Collection
    {
        return Role::query()
            ->with('permissions:id,key,name,group')
            // The number of accounts holding each role, which is what makes
            // "can I delete this?" answerable without a second query per row.
            ->withCount('users')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    public function findRoleByKey(string $key): ?Role
    {
        return Role::query()->with('permissions')->where('key', $key)->first();
    }

    /** @return Collection<int, Permission> */
    public function permissions(): Collection
    {
        return Permission::query()->orderBy('position')->get();
    }

    /**
     * The vocabulary, grouped by module, for the permission matrix.
     *
     * @return array<int, array<string, mixed>>
     */
    public function permissionGroups(): array
    {
        return $this->permissions()
            ->groupBy('group')
            ->map(static fn (Collection $rows, string $group): array => [
                'group' => $group,
                'permissions' => $rows->map(static fn (Permission $permission): array => [
                    'id' => $permission->id,
                    'key' => $permission->key,
                    'name' => $permission->name,
                    'description' => $permission->description,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, Position> */
    public function positions(bool $activeOnly = false): Collection
    {
        return Position::query()
            ->with('defaultRole:id,key,name')
            ->withCount('employees')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /** How many accounts hold a role. Nobody may be left without one. */
    public function usersHolding(string $roleKey): int
    {
        return User::where('role', $roleKey)->count();
    }
}
