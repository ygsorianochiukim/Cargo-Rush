<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Position
 */
class PositionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            // A default, not a rule — the account still names its own role.
            'default_role_id' => $this->default_role_id,
            'default_role_key' => $this->defaultRole?->key,
            'default_role_name' => $this->defaultRole?->name,
            'position' => $this->position,
            'status' => $this->status->value,
            'employee_count' => $this->whenCounted('employees'),

            ...$this->stamps(),
        ];
    }
}
