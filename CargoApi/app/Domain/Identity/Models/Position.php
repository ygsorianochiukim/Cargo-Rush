<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job title the office keeps a list of.
 *
 * Separate from a role because what somebody *is* and what they can *open* are
 * different questions. Conflating them means you cannot have two drivers where
 * one also keeps the books — and that person exists in every small fleet.
 */
class Position extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'key', 'name', 'description', 'default_role_id', 'position', 'status',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    /** The access somebody in this job normally gets. A default, not a rule. */
    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
