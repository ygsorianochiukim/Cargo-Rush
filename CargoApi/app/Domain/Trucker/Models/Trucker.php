<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;
use Database\Factories\TruckerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trucker extends Model
{
    /** @use HasFactory<TruckerFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'business_name', 'phone', 'licence_no', 'licence_expiry',
        'status', 'is_online',
        'latitude', 'longitude', 'located_at', 'trips_completed',
    ];

    protected function casts(): array
    {
        return [
            'licence_expiry' => 'date',
            'located_at' => 'datetime',
            'is_online' => 'boolean',
            'trips_completed' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => StatusValue::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(TruckerVehicle::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(TruckerDriver::class);
    }

    public function walletEntries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    public function isVetted(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function canTakeWork(): bool
    {
        return $this->isVetted() && $this->is_online && $this->activeVehicle() !== null;
    }

    public function fittingVehicle(?int $weightKg, ?string $categoryId): ?TruckerVehicle
    {
        return $this->vehicles
            ->first(static fn (TruckerVehicle $vehicle): bool => $vehicle->canCarry($weightKg, $categoryId));
    }

    public function activeVehicle(): ?TruckerVehicle
    {
        return $this->vehicles
            ->first(static fn (TruckerVehicle $vehicle): bool => $vehicle->isOnTheRoad());
    }

    public function commissionRateBp(): int
    {
        return app(RateBook::class)->for($this->company)->truckerCommissionBp();
    }

    public function hasFreshPosition(int $withinMinutes = 120): bool
    {
        return $this->latitude !== null
            && $this->longitude !== null
            && $this->located_at !== null
            && $this->located_at->isAfter(now()->subMinutes($withinMinutes));
    }

    public function metresTo(?float $lat, ?float $lng): ?float
    {
        if ($lat === null || $lng === null || ! $this->hasFreshPosition()) {
            return null;
        }

        return Geo::metresBetween((float) $this->latitude, (float) $this->longitude, $lat, $lng);
    }

    public function scopeTakingWork(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value)->where('is_online', true);
    }
}
