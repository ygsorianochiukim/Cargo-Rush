<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\Role;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Role
 */
class RoleResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What `users.role` holds, and what the client sends back.
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            // A role the app itself depends on: editable, not deletable.
            'is_system' => $this->is_system,
            // Holds everything, including permissions added later, so its list
            // is not a set of ticks the client can edit.
            'all_permissions' => $this->all_permissions,
            'position' => $this->position,
            'status' => $this->status->value,
            'permissions' => $this->permissionKeys(),
            'permission_count' => $this->all_permissions ? null : $this->permissions->count(),
            // What makes "can I delete this?" answerable on the client.
            'user_count' => $this->whenCounted('users'),

            ...$this->stamps(),
        ];
    }
}
