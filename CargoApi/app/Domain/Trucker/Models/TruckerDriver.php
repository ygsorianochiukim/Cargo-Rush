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

    public function mayDrive(): bool
    {
        return $this->status === StatusValue::Active
            && $this->trucker !== null
            && $this->trucker->isVetted();
    }

    public function employerName(): string
    {
        return $this->trucker?->business_name ?: ($this->trucker?->name ?? 'Trucker');
    }
}
