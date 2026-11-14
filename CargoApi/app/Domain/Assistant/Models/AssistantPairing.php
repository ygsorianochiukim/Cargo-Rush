<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AssistantPairing extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CLAIMED = 'claimed';

    public const DISMISSED = 'dismissed';

    protected $fillable = ['code', 'secret_hash', 'company_id', 'token', 'status', 'ip_address', 'expires_at', 'approved_at', 'claimed_at'];

    protected $hidden = ['secret_hash', 'token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->where('expires_at', '>', now());
    }

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }
}
