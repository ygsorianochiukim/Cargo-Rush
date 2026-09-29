<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody a trucker employs to drive their trucks.
 *
 * Deliberately not a `Driver`. That model is Cargo Rush's own crew — payroll,
 * the daily sheet, Drivers Management — and this person is on none of them.
 * They belong to the trucker, run only the trips the trucker hands them, and
 * sign in with a login of their own. See the migration for why the two never
 * share a table.
 */
class TruckerDriver extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'trucker_id', 'user_id', 'name', 'phone', 'licence_no', 'licence_expiry', 'status',
    ];

    protected function casts(): array
    {
        return [
            'licence_expiry' => 'date',
            'status' => StatusValue::class,
        ];
    }

    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * May they run anything right now?
     *
     * Both halves: the owner has not stood them down, and the office has not
     * stood the owner down. A suspended trucker's drivers are suspended with
     * them — they only ever drove under that trucker's standing.
     */
    public function mayDrive(): bool
    {
        return $this->status === StatusValue::Active
            && $this->trucker !== null
            && $this->trucker->isVetted();
    }

    /**
     * Who they drive for, in words — the label that keeps them apart from
     * Cargo Rush's own drivers on every screen that lists people.
     */
    public function employerName(): string
    {
        return $this->trucker?->business_name ?: ($this->trucker?->name ?? 'Trucker');
    }
}
