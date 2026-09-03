<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing an account can be allowed to do.
 *
 * The vocabulary, and the one table here that stays the developer's: a
 * permission only means something if code checks for it, so a row invented in
 * the UI would gate nothing. `PermissionSeeder` is where new ones are added.
 */
class Permission extends Model
{
    use HasUlids;

    protected $fillable = ['key', 'name', 'description', 'group', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
