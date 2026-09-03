<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on the rate card: within this distance, this is the price.
 *
 * The arithmetic lives here rather than in the service because the bracket is
 * the thing that carries the rates, and a quote that reads four columns out of
 * a model to add them up somewhere else is a quote that can be computed two
 * ways.
 */
class PricingBracket extends Model
{
    use HasUlids;

    protected $fillable = [
        'zone_id', 'label', 'min_km', 'max_km',
        'base_cents', 'per_km_cents', 'per_kg_cents', 'minimum_cents', 'position',
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
            'position' => 'integer',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(PricingZone::class, 'zone_id');
    }

    /**
     * Half-open on purpose: `min_km` inclusive, `max_km` exclusive.
     *
     * A card of 0–20 and 20–50 then prices a 20 km run once, in the second
     * bracket. Inclusive on both ends and it matches both, and which one wins
     * depends on row order — a quote that changes when somebody drags a row.
     */
    public function covers(int $km): bool
    {
        return $km >= $this->min_km
            && ($this->max_km === null || $km < $this->max_km);
    }

    /**
     * The card price for a run, before any fuel adjustment.
     *
     * Distance is charged by the whole kilometre, rounded up, the way a fare
     * is — a 1.2 km run is two, not one plus a fraction of a centavo nobody
     * can put on an invoice.
     */
    public function priceFor(int $km, int $weightKg): int
    {
        $price = $this->base_cents
            + max(0, $km) * $this->per_km_cents
            + max(0, $weightKg) * $this->per_kg_cents;

        return max($price, $this->minimum_cents);
    }

    /** "Within 20 km" / "20 – 50 km" / "80 km and beyond", for a client. */
    public function range(): string
    {
        if ($this->min_km === 0 && $this->max_km !== null) {
            return "Within {$this->max_km} km";
        }

        if ($this->max_km === null) {
            return "{$this->min_km} km and beyond";
        }

        return "{$this->min_km} – {$this->max_km} km";
    }
}
