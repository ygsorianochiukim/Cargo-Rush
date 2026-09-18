<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\DieselPriceRepository;

/**
 * How far today's pump price has moved the rate card.
 *
 * The whole point of the feature: a card is drawn at some assumed diesel
 * price, and when the pump moves the card is stale by roughly the fuel share
 * of a run. Rather than have the office retype every band, the difference is
 * derived — and derived in exactly one place, so a quote, a preview and the
 * figure shown on the pricing screen cannot disagree.
 *
 * ## Two rules, and why both
 *
 * **A step per peso**, which is what a subsidy table states. The workbook's
 * card prints a column of pesos beside each band — A1 adds ₱14 for every ₱1/L
 * diesel sits above the baseline, O adds ₱210 — and then tabulates the result
 * out to ₱129/L so the desk can read a figure off rather than compute one. At
 * ₱85/L against a ₱43 baseline, A1 is 4,165 + 14 × 42 = ₱4,753, which is
 * exactly what the table's ₱85 column says.
 *
 * **A percentage of the fare**, which is what was here before: the pump's
 * movement passed through at a fuel share and clamped. It is the right model
 * for a firm that has one card and no table behind it.
 *
 * They are not interchangeable, and that is the reason for keeping both rather
 * than picking. A single fuel share produces a surcharge proportional to the
 * base, and a table's is not: ₱14 on ₱4,165 is 0.34% per peso of diesel, while
 * ₱210 on ₱37,206 is 0.56%. Long hauls burn more fuel per peso of fare, the
 * table says so in its own numbers, and flattening that would under-recover on
 * the long bands and over-recover on the short ones.
 *
 * So the step applies where a line declares one, the percentage everywhere
 * else. An install pricing off `sensitivity` and `cap_bp` today keeps precisely
 * the quotes it has.
 *
 * The percentage returns basis points, signed. 425 is +4.25%; -80 is a
 * discount because diesel fell. A surcharge that could only be positive would
 * keep charging for a rise that has since reversed, which is the version of
 * this feature customers notice and complain about.
 */
class FuelIndex
{
    public function __construct(private readonly DieselPriceRepository $prices) {}

    /**
     * What this line adds for diesel, in centavos, and how the figure was got.
     *
     * One method rather than two called in turn, because the choice between
     * the step and the percentage has to be made once. Made twice — once to
     * decide the surcharge and again to decide what to record — is how a trip
     * ends up with both a `fuel_surcharge_cents` and a `fuel_adjustment_bp`
     * that each claim to explain the same peso.
     *
     * `rule` says which one answered, and is `none` when no pump price has
     * been recorded at all. That distinction is worth a word of its own: a
     * stepped line on a fresh install adds nothing, and so does a stepped line
     * with diesel sitting inside the baseline band, and only the first of
     * those is something the office needs to go and fix.
     *
     * @return array{cents: int, bp: int, stepped: bool, pesos_above: int, rule: string}
     */
    public function surchargeFor(PricingBracket $bracket, int $cardCents, ?PricingZone $zone = null): array
    {
        $baseline = $this->baselineFor($zone);
        $current = $this->currentPriceCents();

        if ($bracket->hasDieselStep()) {
            return [
                'cents' => $bracket->dieselSurcharge($current, $baseline),
                // Kept at zero rather than derived. A flat peso surcharge is
                // not a percentage of the fare, and writing one into the bp
                // column would put a rounded, meaningless figure in the place
                // the office looks to see what priced a trip.
                'bp' => 0,
                'stepped' => true,
                'pesos_above' => $bracket->pesosAboveBaseline($current, $baseline),
                'rule' => $current === null ? 'none' : 'step',
            ];
        }

        $bp = $this->adjustmentBp($zone);

        return [
            'cents' => $this->apply($cardCents, $bp) - $cardCents,
            'bp' => $bp,
            'stepped' => false,
            'pesos_above' => 0,
            'rule' => $current === null ? 'none' : 'percentage',
        ];
    }

    /**
     * The percentage adjustment in force, in basis points.
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
     * Apply a percentage adjustment to a card price.
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

    /**
     * What the card was drawn at: the zone's own baseline, or the install's.
     *
     * On a subsidy-table card this is the **top of the band the printed price
     * covers** — the workbook's card holds from ₱30 to ₱43 a litre, and the
     * figures in the `Current Price` column are what to charge anywhere inside
     * it. So ₱43.00 is the zone's baseline and diesel at ₱38 adds nothing,
     * which is what the table means rather than a discount it forgot to offer.
     */
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
