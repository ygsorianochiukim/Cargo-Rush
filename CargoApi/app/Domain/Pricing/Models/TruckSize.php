<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TruckSize extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = ['key', 'name', 'cbm', 'status'];

    protected function casts(): array
    {
        return [
            'cbm' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function truckerVehicles(): HasMany
    {
        return $this->hasMany(TruckerVehicle::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeSmallestFirst(Builder $query): Builder
    {
        return $query->orderBy('cbm')->orderBy('name');
    }

    public static function sizedForWheels(?int $wheels): ?self
    {
        if ($wheels === null) {
            return null;
        }

        return self::query()
            ->active()
            ->smallestFirst()
            ->get()
            ->first(fn (self $size): bool => $size->wheels() === $wheels);
    }

    public function wheels(): ?int
    {
        if (preg_match('/^(\d+)-wheeler$/', (string) $this->key, $m) === 1
            || preg_match('/^\s*(\d+)\s*-?\s*wheel/i', (string) $this->name, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    public function sizeUnsizedVehicles(): int
    {
        $wheels = $this->wheels();

        if ($wheels === null || ! $this->isActive()) {
            return 0;
        }

        return Vehicle::query()
            ->where('wheels', $wheels)
            ->whereNull('truck_size_id')
            ->update(['truck_size_id' => $this->id]);
    }
}
