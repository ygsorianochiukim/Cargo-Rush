<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PricingZone extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'min_km', 'max_km', 'diesel_baseline_cents',
        'position', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'min_km' => 'integer',
            'max_km' => 'integer',
            'diesel_baseline_cents' => 'integer',
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function brackets(): HasMany
    {
        return $this->hasMany(PricingBracket::class, 'zone_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function covers(int $km): bool
    {
        return $km >= $this->min_km
            && ($this->max_km === null || $km < $this->max_km);
    }

    public function clamp(int $km): int
    {
        $km = max($km, (int) $this->min_km);

        return $this->max_km === null ? $km : min($km, (int) $this->max_km - 1);
    }

    public function band(): string
    {
        if ($this->max_km === null) {
            return "{$this->min_km} km and beyond";
        }

        return "{$this->min_km} – ".($this->max_km - 1).' km';
    }

    public function bracketFor(?string $truckCategoryId = null): ?PricingBracket
    {
        return $this->brackets
            ->sortByDesc(fn (PricingBracket $bracket): int => $bracket->specificity())
            ->first(fn (PricingBracket $bracket): bool => $bracket->appliesTo(
                $this->min_km,
                $truckCategoryId,
            ));
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeCovering(Builder $query, int $km): Builder
    {
        return $query->where('min_km', '<=', $km)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('max_km')
                ->orWhere('max_km', '>', $km));
    }

    public function scopeInBandOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('min_km')->orderBy('code');
    }
}
