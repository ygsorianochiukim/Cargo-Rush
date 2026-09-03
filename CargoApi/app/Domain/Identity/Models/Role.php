<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named bundle of permissions.
 *
 * Shares a name with `App\Domain\Shared\Enums\Role`, and the two are not the
 * same thing. The enum names the five roles the app ships with and is what
 * code refers to when it means one of them specifically — "notify the
 * administrators". This is the editable record, and there can be any number of
 * them. Files needing both alias the enum.
 */
class Role extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'key', 'name', 'description', 'is_system', 'all_permissions', 'position', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'all_permissions' => 'boolean',
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** Accounts holding this role. Matched on the key, not on an id. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'key');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'default_role_id');
    }

    /**
     * The permission keys this role grants.
     *
     * `*` for a role marked `all_permissions`, which is how the administrator
     * keeps reaching permissions added in a later release rather than silently
     * losing them until somebody notices an unticked box.
     *
     * @return string[]
     */
    public function permissionKeys(): array
    {
        if ($this->all_permissions) {
            return ['*'];
        }

        return $this->permissions->pluck('key')->all();
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
