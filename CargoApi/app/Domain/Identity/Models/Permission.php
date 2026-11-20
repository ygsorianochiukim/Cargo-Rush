<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasUlids;

    public const DEVELOPER_ACCESS = 'automation.manage';

    protected $fillable = ['key', 'name', 'description', 'group', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @param  list<string>  $held */
    public static function allows(array $held, string $permission): bool
    {
        return in_array($permission, $held, true)
            || ($permission !== self::DEVELOPER_ACCESS && in_array('*', $held, true));
    }
}
