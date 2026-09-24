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

/**
 * A zone: a band of kilometres, named the way a subsidy table names it.
 *
 * `A1` is 1–40 km. `B` is 41–80. `O` is 561–600. That is the whole of what a
 * zone is, and it is what the trade's own rate tables mean by the word — a
 * column headed `Zone` beside a column headed `Distance`, with money to the
 * right of both.
 *
 * It used to be a service area matched from a booking's destination, and the
 * shape of that idea is why this is a rewrite rather than a rename. A place
 * cannot express the one thing these tables do constantly: **two zones over
 * the same band.** A1 and A2 are both 1–40 km at ₱4,165 and ₱4,412, and E1 and
 * E2 are both 161–200 km — the difference between them is a commercial
 * decision about a particular run, not a fact about where it is going, so no
 * amount of alias matching can pick between them.
 *
 * So the band narrows a quote to the zones that could apply, and the desk says
 * which. `ZoneResolver` holds that rule.
 */
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

    /**
     * Does a run of this many kilometres fall in this zone's band?
     *
     * Half-open — `min_km` inclusive, `max_km` exclusive — which is the
     * convention every range in this module uses. A table written "1 to 40"
     * and "41 to 80" is stored as [1, 41) and [41, 81), so 41 km belongs to
     * exactly one band. Closed on both ends and it belongs to two, and which
     * one prices it depends on row order.
     */
    public function covers(int $km): bool
    {
        return $km >= $this->min_km
            && ($this->max_km === null || $km < $this->max_km);
    }

    /**
     * "1 – 40 km" / "561 km and beyond", for a client.
     *
     * The upper bound is printed inclusive — `max_km` less one — because the
     * half-open range is a storage decision and "1 – 41 km" beside a table
     * that says "1 to 40" would read as a discrepancy to the only people who
     * check.
     */
    public function band(): string
    {
        if ($this->max_km === null) {
            return "{$this->min_km} km and beyond";
        }

        return "{$this->min_km} – ".($this->max_km - 1).' km';
    }

    /** The rate line for a kind of unit, most specific first, or null. */
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

    /**
     * The zones whose band covers this distance.
     *
     * Plural on purpose. A subsidy table routinely has two rows over one band,
     * and a resolver that assumed one would silently drop A2 — a quote short
     * by ₱247 with nothing in the data to say why.
     */
    public function scopeCovering(Builder $query, int $km): Builder
    {
        return $query->where('min_km', '<=', $km)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('max_km')
                ->orWhere('max_km', '>', $km));
    }

    /** Down the card the way the table prints it: by band, then by name. */
    public function scopeInBandOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('min_km')->orderBy('code');
    }
}
