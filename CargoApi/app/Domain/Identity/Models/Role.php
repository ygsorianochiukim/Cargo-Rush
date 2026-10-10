<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

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

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'key');
    }

    /** @return string[] */
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
