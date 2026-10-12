<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TruckCategory extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = ['key', 'name', 'description', 'cbm', 'position', 'status'];

    protected function casts(): array
    {
        return [
            'cbm' => 'integer',
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function brackets(): HasMany
    {
        return $this->hasMany(PricingBracket::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public static function sizedForWheels(?int $wheels): ?self
    {
        if ($wheels === null) {
            return null;
        }

        return self::query()
            ->active()
            ->whereNotNull('cbm')
            ->inCardOrder()
            ->get()
            ->first(fn (self $category): bool => $category->wheels() === $wheels);
    }

    public function wheels(): ?int
    {
        if ($this->cbm === null) {
            return null;
        }

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
            ->whereNull('truck_category_id')
            ->update(['truck_category_id' => $this->id]);
    }

    public function scopeInCardOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
