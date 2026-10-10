<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repositories;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AccessRepository
{
    /** @return Collection<int, Role> */
    public function roles(bool $activeOnly = false): Collection
    {
        return Role::query()
            ->with('permissions:id,key,name,group')
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

    /** @return array<int, array<string, mixed>> */
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
            ->withCount('employees')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    public function usersHolding(string $roleKey): int
    {
        return User::where('role', $roleKey)->count();
    }
}
