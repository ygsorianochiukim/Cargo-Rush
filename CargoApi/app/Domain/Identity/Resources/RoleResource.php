<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\Role;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Role */
class RoleResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'all_permissions' => $this->all_permissions,
            'position' => $this->position,
            'status' => $this->status->value,
            'permissions' => $this->permissionKeys(),
            'permission_count' => $this->all_permissions ? null : $this->permissions->count(),
            'user_count' => $this->whenCounted('users'),

            ...$this->stamps(),
        ];
    }
}
