<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingBracket extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'zone_id', 'truck_category_id', 'label', 'min_km', 'max_km',
        'base_cents', 'per_km_cents', 'per_kg_cents', 'minimum_cents',
        'diesel_step_cents', 'position',
    ];

    protected function casts(): array
    {
        return [
            'min_km' => 'integer',
            'max_km' => 'integer',
            'base_cents' => 'integer',
            'per_km_cents' => 'integer',
            'per_kg_cents' => 'integer',
            'minimum_cents' => 'integer',
            'diesel_step_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(PricingZone::class, 'zone_id');
    }

    public function truckCategory(): BelongsTo
    {
        return $this->belongsTo(TruckCategory::class, 'truck_category_id');
    }

    public function specificity(): int
    {
        return ($this->zone_id === null ? 0 : 2) + ($this->truck_category_id === null ? 0 : 1);
    }

    public function appliesTo(int $km, ?string $truckCategoryId): bool
    {
        if (! $this->covers($km)) {
            return false;
        }

        return $this->truck_category_id === null
            || $this->truck_category_id === $truckCategoryId;
    }

    public function covers(int $km): bool
    {
        if ($this->min_km === null && $this->max_km === null) {
            return $this->zone === null || $this->zone->covers($km);
        }

        return $km >= (int) $this->min_km
            && ($this->max_km === null || $km < $this->max_km);
    }

    public function priceFor(int $km, int $weightKg): int
    {
        $price = $this->base_cents
            + max(0, $km) * $this->per_km_cents
            + max(0, $weightKg) * $this->per_kg_cents;

        return max($price, $this->minimum_cents);
    }

    public function hasDieselStep(): bool
    {
        return $this->diesel_step_cents > 0;
    }

    public function dieselSurcharge(?int $currentCents, int $baselineCents): int
    {
        if (! $this->hasDieselStep() || $currentCents === null) {
            return 0;
        }

        return $this->diesel_step_cents * $this->pesosAboveBaseline($currentCents, $baselineCents);
    }

    public function pesosAboveBaseline(?int $currentCents, int $baselineCents): int
    {
        if ($currentCents === null) {
            return 0;
        }

        return max(0, intdiv($currentCents - $baselineCents, 100));
    }

    public function range(): string
    {
        if ($this->min_km === null && $this->max_km === null) {
            return $this->zone?->band() ?? 'Any distance';
        }

        $min = (int) $this->min_km;

        if ($min === 0 && $this->max_km !== null) {
            return "Within {$this->max_km} km";
        }

        if ($this->max_km === null) {
            return "{$min} km and beyond";
        }

        return "{$min} – {$this->max_km} km";
    }
}
