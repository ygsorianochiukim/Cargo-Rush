<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of money on the rate card.
 *
 * Two kinds of line live in this table, and the difference is whether the row
 * names a zone.
 *
 * **A zone's line** is a row of a subsidy table read across: the band comes
 * from the zone, and the line holds the base and the diesel step for one class
 * of truck. `min_km`/`max_km` are null, because the zone already said 1–40 km
 * and repeating it here is how the two end up disagreeing.
 *
 * **A zoneless line** is the firm's plain distance card — "450 km is ₱5,000" —
 * and carries its own kilometres, exactly as it always did.
 *
 * The arithmetic lives here rather than in the service because this is the
 * thing that holds the rates, and a quote that reads five columns off a model
 * to add them up somewhere else is a quote that can be computed two ways.
 */
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

    /** The kind of unit this line prices. Null means any. */
    public function truckCategory(): BelongsTo
    {
        return $this->belongsTo(TruckCategory::class, 'truck_category_id');
    }

    /**
     * How specific this line is — the tie-breaker when several cover a run.
     *
     * A zone's card can hold a general line and a line for one class of truck
     * at once, and both cover the same run. The office means the more
     * particular one; anything else makes a rate card something you have to
     * reason about row order to read.
     *
     * Two points for a zone, one for a category — deliberately, not because a
     * band matters more in general, but because a firm that has drawn a zone
     * has named a *band of the table*, and that is the more specific statement
     * about a run than the kind of box on the back of the truck. They never
     * collide anyway: the four combinations score 0, 1, 2 and 3.
     */
    public function specificity(): int
    {
        return ($this->zone_id === null ? 0 : 2) + ($this->truck_category_id === null ? 0 : 1);
    }

    /**
     * Does this line apply to a run of this distance and this kind of unit?
     *
     * A line naming no category answers for every unit, which is what a
     * subsidy table's `Current Price` column is — the rate for whatever the
     * fleet mostly runs, with the other classes priced beside it.
     */
    public function appliesTo(int $km, ?string $truckCategoryId): bool
    {
        if (! $this->covers($km)) {
            return false;
        }

        return $this->truck_category_id === null
            || $this->truck_category_id === $truckCategoryId;
    }

    /**
     * Does this line's distance cover a run of this many kilometres?
     *
     * Null kilometres defer to the zone, which is what every line on a
     * subsidy-table card holds: the zone said 1–40 km, and a line that also
     * carried a band could be edited into disagreeing with it. A line with no
     * zone *and* no kilometres covers everything — the honest reading of a row
     * that names no limit at all.
     *
     * Where the line does carry its own band it is half-open, `min_km`
     * inclusive and `max_km` exclusive, so a card of 0–20 and 20–50 prices a
     * 20 km run once. Inclusive on both ends and it matches both, and which
     * one wins depends on which row somebody dragged where.
     */
    public function covers(int $km): bool
    {
        if ($this->min_km === null && $this->max_km === null) {
            return $this->zone === null || $this->zone->covers($km);
        }

        return $km >= (int) $this->min_km
            && ($this->max_km === null || $km < $this->max_km);
    }

    /**
     * The card price for a run, before any fuel adjustment.
     *
     * A subsidy-table line is base alone — the band already accounts for the
     * distance, which is the entire point of banding it, and charging per
     * kilometre on top would bill the same distance twice. The per-km and
     * per-kg rates stay for the plain distance card, where they are the whole
     * of how a price is built, and are zero on a banded line.
     */
    public function priceFor(int $km, int $weightKg): int
    {
        $price = $this->base_cents
            + max(0, $km) * $this->per_km_cents
            + max(0, $weightKg) * $this->per_kg_cents;

        return max($price, $this->minimum_cents);
    }

    /**
     * Does this line price diesel the way a subsidy table does?
     *
     * A declared step is the table's own arithmetic — so many pesos per ₱1/L
     * above the baseline — and takes precedence over the percentage model for
     * this line alone. No step means the line predates the change or was drawn
     * by hand, and it keeps the percentage adjustment it has always had.
     */
    public function hasDieselStep(): bool
    {
        return $this->diesel_step_cents > 0;
    }

    /**
     * What diesel adds to this line at today's pump price, in centavos.
     *
     * Whole pesos of movement, rounded down, because that is the unit the
     * table is written in: its columns step ₱85, ₱86, ₱87, and a pump price of
     * ₱85.60 sits on the ₱85 column rather than between two of them. Rounding
     * up instead would charge for a peso the table never printed.
     *
     * Never negative. The baseline is the **top of a band** — the workbook's
     * card holds from ₱30 to ₱43 a litre — so diesel inside that band is what
     * the printed price already assumes, not a discount owed back.
     */
    public function dieselSurcharge(?int $currentCents, int $baselineCents): int
    {
        if (! $this->hasDieselStep() || $currentCents === null) {
            return 0;
        }

        return $this->diesel_step_cents * $this->pesosAboveBaseline($currentCents, $baselineCents);
    }

    /** How many whole pesos a litre the pump sits above the card's baseline. */
    public function pesosAboveBaseline(?int $currentCents, int $baselineCents): int
    {
        if ($currentCents === null) {
            return 0;
        }

        return max(0, intdiv($currentCents - $baselineCents, 100));
    }

    /**
     * "1 – 40 km" / "80 km and beyond", for a client.
     *
     * A banded line has no kilometres of its own and borrows the zone's, so
     * the range a customer is shown is the range the table prints.
     */
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
