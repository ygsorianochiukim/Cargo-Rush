<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Models\TruckSize;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\TruckVerification;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\TruckerVehicleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TruckerVehicle extends Model
{
    /** @use HasFactory<TruckerVehicleFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'trucker_id', 'plate', 'model', 'capacity_kg', 'truck_category_id', 'truck_size_id', 'status',
        'photo_front_path', 'photo_left_path', 'photo_right_path', 'photo_back_path',
        'photo_plate_path', 'photo_engine_path',
        'verification', 'verified_at', 'verified_by', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'integer',
            'status' => StatusValue::class,
            'verification' => TruckVerification::class,
            'verified_at' => 'datetime',
        ];
    }

    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TruckCategory::class, 'truck_category_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(TruckSize::class, 'truck_size_id');
    }

    public function isVerified(): bool
    {
        return $this->verification === TruckVerification::Verified;
    }

    public function isOnTheRoad(): bool
    {
        return $this->status === StatusValue::Available && $this->isVerified();
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function canCarry(?int $weightKg, ?string $categoryId, ?string $sizeId = null): bool
    {
        if (! $this->isOnTheRoad()) {
            return false;
        }

        if ($weightKg !== null && $weightKg > $this->capacity_kg) {
            return false;
        }

        $fitsSize = $sizeId === null
            || $this->truck_size_id === null
            || $this->truck_size_id === $sizeId;

        return $fitsSize && ($categoryId === null
            || $this->truck_category_id === null
            || $this->truck_category_id === $categoryId);
    }
}
