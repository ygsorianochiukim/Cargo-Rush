<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Repositories\AccessRepository;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessService
{
    public function __construct(private readonly AccessRepository $access) {}

    /** @param array<string, mixed> $attributes */
    public function createRole(array $attributes): Role
    {
        return DB::transaction(function () use ($attributes): Role {
            $role = Role::create([
                'key' => $this->uniqueKey(
                    Str::slug((string) ($attributes['key'] ?? $attributes['name'])),
                ),
                'name' => (string) $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'is_system' => false,
                'all_permissions' => false,
                'position' => (int) ($attributes['position'] ?? 500),
                'status' => $attributes['status'] ?? StatusValue::Active->value,
            ]);

            $this->syncPermissions($role, $attributes['permissions'] ?? []);

            return $role->refresh()->load('permissions');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateRole(Role $role, array $attributes): Role
    {
        return DB::transaction(function () use ($role, $attributes): Role {
            $role->update(array_intersect_key(
                $attributes,
                array_flip(['name', 'description', 'position', 'status']),
            ));

            if (array_key_exists('permissions', $attributes)) {
                $this->syncPermissions($role, $attributes['permissions']);
            }

            return $role->refresh()->load('permissions');
        });
    }

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

        $role->delete();
    }

    /** @param array<int, string> $keys */
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

    /** @param array<string, mixed> $attributes */
    public function createPosition(array $attributes): Position
    {
        $position = Position::create([
            'key' => $this->uniquePositionKey(
                Str::slug((string) ($attributes['key'] ?? $attributes['name'])),
            ),
            'name' => (string) $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'drives' => (bool) ($attributes['drives'] ?? false),
            'position' => (int) ($attributes['position'] ?? 500),
            'status' => $attributes['status'] ?? StatusValue::Active->value,

            'pay_basis' => $attributes['pay_basis'] ?? PayBasis::Monthly->value,
            'trainee_amount_cents' => (int) ($attributes['trainee_amount_cents'] ?? 0),
            'probationary_amount_cents' => (int) ($attributes['probationary_amount_cents'] ?? 0),
            'regular_amount_cents' => (int) ($attributes['regular_amount_cents'] ?? 0),
        ]);

        return $position->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function updatePosition(Position $position, array $attributes): Position
    {
        $position->update(array_intersect_key(
            $attributes,
            array_flip([
                'name', 'description', 'drives', 'position', 'status',
                'pay_basis', 'trainee_amount_cents', 'probationary_amount_cents',
                'regular_amount_cents',
            ]),
        ));

        return $position->refresh();
    }

    public function deletePosition(Position $position): bool
    {
        if ($position->employees()->exists()) {
            $position->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $position->delete();

        return true;
    }

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
