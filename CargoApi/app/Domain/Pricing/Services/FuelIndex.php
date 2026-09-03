<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\DieselPriceRepository;

/**
 * How far today's pump price has moved the rate card.
 *
 * The whole point of the feature: a card is drawn at some assumed diesel
 * price, and when the pump moves the card is stale by roughly the fuel share
 * of a run. Rather than have the office retype every bracket, the difference
 * is derived — and derived in exactly one place, so a quote, a preview and the
 * figure shown on the pricing screen cannot disagree.
 *
 * Returns basis points, signed. 425 is +4.25%; -80 is a discount because
 * diesel fell. A surcharge that could only be positive would keep charging
 * for a rise that has since reversed, which is the version of this feature
 * customers notice and complain about.
 */
class FuelIndex
{
    public function __construct(private readonly DieselPriceRepository $prices) {}

    /**
     * The adjustment in force, in basis points.
     *
     * Zero when nobody has recorded a price yet, which is the state of a fresh
     * install: no reading means no evidence the card is stale, and inventing a
     * surcharge from a config default would bill a customer for a pump price
     * the office never entered.
     */
    public function adjustmentBp(?PricingZone $zone = null): int
    {
        $current = $this->prices->current();

        if ($current === null) {
            return 0;
        }

        $baseline = $this->baselineFor($zone);

        if ($baseline <= 0) {
            return 0;
        }

        $moveBp = (int) round((($current->price_per_litre_cents - $baseline) / $baseline) * 10_000);
        $sensitivity = (float) config('cargo.diesel.sensitivity');
        $cap = abs((int) config('cargo.diesel.cap_bp'));

        return max(-$cap, min($cap, (int) round($moveBp * $sensitivity)));
    }

    /**
     * Apply an adjustment to a card price.
     *
     * Kept beside `adjustmentBp()` so the rounding happens once. Rounding a
     * percentage into a peso figure in two places is how a quote preview and
     * the saved trip end up a centavo apart, which reads to the office as the
     * system changing its mind.
     */
    public function apply(int $cents, int $adjustmentBp): int
    {
        if ($adjustmentBp === 0) {
            return $cents;
        }

        return (int) max(0, round($cents * (10_000 + $adjustmentBp) / 10_000));
    }

    /** What the card was drawn at: the zone's own baseline, or the install's. */
    public function baselineFor(?PricingZone $zone = null): int
    {
        return $zone?->diesel_baseline_cents ?? (int) config('cargo.diesel.baseline_cents');
    }

    /** The current pump price in centavos per litre, or null if none recorded. */
    public function currentPriceCents(): ?int
    {
        return $this->prices->current()?->price_per_litre_cents;
    }
}
