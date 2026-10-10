<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalletEntry extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $table = 'trucker_wallet_entries';

    protected $fillable = [
        'trucker_id', 'trip_id', 'kind', 'status', 'method', 'source', 'amount_cents',
        'gross_cents', 'rate_bp', 'reference', 'note', 'occurred_on', 'recorded_by',
        'settled_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'gross_cents' => 'integer',
            'rate_bp' => 'integer',
            'occurred_on' => 'date',
            'settled_at' => 'datetime',
            'kind' => WalletEntryKind::class,
            'status' => StatusValue::class,
            'source' => BookingSource::class,
        ];
    }

    public function isInFlight(): bool
    {
        return $this->status === StatusValue::Pending;
    }

    public function scopeLanded(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Paid->value);
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Pending->value);
    }

    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function describe(): string
    {
        $reference = $this->trip?->reference;

        return match ($this->kind) {
            WalletEntryKind::Earning => $reference === null
                ? 'Trip earning'
                : "Earned on {$reference}",
            WalletEntryKind::Commission => $reference === null
                ? 'Commission'
                : "Commission on {$reference}",
            WalletEntryKind::Payout => 'Paid out'.($this->reference === null ? '' : " · {$this->reference}"),
            WalletEntryKind::Remittance => 'Remitted'.($this->reference === null ? '' : " · {$this->reference}"),
            WalletEntryKind::Adjustment => $this->note ?? 'Adjustment',
        };
    }

    public static function balanceFor(string $truckerId): int
    {
        return (int) static::query()
            ->where('trucker_id', $truckerId)
            ->landed()
            ->sum('amount_cents');
    }

    public static function inFlightFor(string $truckerId): int
    {
        return abs((int) static::query()
            ->where('trucker_id', $truckerId)
            ->inFlight()
            ->sum('amount_cents'));
    }

    public function scopeFromWork(Builder $query): Builder
    {
        return $query->whereIn('kind', [
            WalletEntryKind::Earning->value,
            WalletEntryKind::Commission->value,
        ]);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settled_by');
    }

    public function isSettled(): bool
    {
        return $this->settled_by !== null;
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->fromWork()->whereNull('settled_by');
    }

    public function paymentState(): ?string
    {
        if (! $this->kind->isFromWork()) {
            return null;
        }

        if (! $this->isSettled()) {
            return 'unpaid';
        }

        return $this->settlement?->isInFlight() ? 'processing' : 'paid';
    }
}
