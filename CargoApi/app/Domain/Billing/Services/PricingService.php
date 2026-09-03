<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Pricing\DTO\QuoteBreakdown;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Services\FuelIndex;
use App\Domain\Pricing\Services\ZoneResolver;
use App\Domain\Trip\Models\Trip;

/**
 * What a haul is charged, worked out rather than typed.
 *
 * DESIGN.md section 5.1 says income is entered and only the totals derived,
 * and for the transcribed workbook that was right: those rows are a record of
 * days that already happened, at prices already agreed. It stopped being right
 * the moment a customer could book their own delivery — there is nobody to
 * type a figure at that point, and quoting one later means the customer agreed
 * to a price they were never shown.
 *
 * So a trip is quoted, at booking, from the three facts a booking carries:
 * where it is going, how far, and how heavy.
 *
 *   1. The destination picks a zone off the rate card (`ZoneResolver`).
 *   2. The distance picks a bracket inside that zone, which holds the rates.
 *   3. Today's pump price scales the result (`FuelIndex`).
 *
 * With the fallback that makes the whole thing safe: a destination no zone
 * claims is priced from `config/cargo.php` exactly as it was before any of
 * this existed —
 *
 *     price = base + (per_km * km) + (per_kg * kg)   floored at `minimum`
 *
 * so an install that never opens the zone editor is unaffected, and a
 * misspelled town produces a defensible figure rather than a zero.
 *
 * This is the only place either arithmetic exists. The ledger and the invoice
 * both read the price off the trip, so the sheet, the document and what the
 * customer was quoted cannot disagree.
 */
class PricingService
{
    public function __construct(
        private readonly ZoneResolver $zones,
        private readonly FuelIndex $fuel,
    ) {}

    /** The quote for a trip, in centavos. */
    public function quote(Trip $trip): int
    {
        return $this->breakdown($trip)->cents;
    }

    /** The same figure with its reasoning attached, for storing or showing. */
    public function breakdown(Trip $trip): QuoteBreakdown
    {
        return $this->breakdownFor(
            distanceM: (int) $trip->distance_total_m,
            weightKg: (int) $trip->weight_kg,
            destination: $trip->destination,
            origin: $trip->origin,
        );
    }

    /**
     * The same calculation from raw figures, for a quote before a row exists.
     *
     * Distance is metres here and kilometres on the card, rounded up: a 1.2 km
     * run is charged as two, the way a fare is, rather than as one plus a
     * fraction of a centavo nobody can invoice.
     *
     * An unmapped trip has no distance — booked over the phone against a town
     * name, which is the common case — and lands in the zone's first bracket on
     * base plus weight alone. That is a smaller number than the real haul
     * deserves, and the office can correct it; it is not zero, which is what
     * the ledger used to get.
     */
    public function breakdownFor(
        int $distanceM,
        int $weightKg,
        ?string $destination = null,
        ?string $origin = null,
    ): QuoteBreakdown {
        $km = (int) ceil(max(0, $distanceM) / 1000);
        $weightKg = max(0, $weightKg);

        $zone = $this->zones->forTrip($destination, $origin);
        $bracket = $zone?->bracketFor($km);

        // A zone whose card has no bracket covering this distance is a gap the
        // office left — 0–20 and 50–100 with nothing between. Falling back to
        // the tariff is the honest answer: it is a real price, and it is not
        // the card's, which is exactly what the trace columns will say.
        if ($zone === null || $bracket === null) {
            return $this->fromTariff($km, $weightKg, $zone);
        }

        $card = $bracket->priceFor($km, $weightKg);
        $adjustmentBp = $this->fuel->adjustmentBp($zone);

        return new QuoteBreakdown(
            cents: $this->fuel->apply($card, $adjustmentBp),
            cardCents: $card,
            km: $km,
            weightKg: $weightKg,
            fuelAdjustmentBp: $adjustmentBp,
            currency: $this->currency(),
            source: 'zone',
            zoneId: $zone->id,
            zoneName: $zone->name,
            bracketId: $bracket->id,
            bracketLabel: $bracket->label,
            bracketRange: $bracket->range(),
            dieselCents: $this->fuel->currentPriceCents(),
            dieselBaselineCents: $this->fuel->baselineFor($zone),
        );
    }

    /**
     * The install-wide tariff, unchanged from before the rate card existed.
     *
     * The fuel adjustment is deliberately *not* applied here. The config rates
     * are a fallback nobody drew at a particular pump price, so there is no
     * baseline to be stale against — scaling them would be arithmetic on a
     * number that means nothing.
     */
    private function fromTariff(int $km, int $weightKg, ?PricingZone $zone): QuoteBreakdown
    {
        $tariff = (array) config('cargo.tariff');

        $price = (int) $tariff['base_cents']
            + $km * (int) $tariff['per_km_cents']
            + $weightKg * (int) $tariff['per_kg_cents'];

        $price = max($price, (int) $tariff['minimum_cents']);

        return new QuoteBreakdown(
            cents: $price,
            cardCents: $price,
            km: $km,
            weightKg: $weightKg,
            fuelAdjustmentBp: 0,
            currency: $this->currency(),
            source: 'tariff',
            // Named even though it did not price this run, so the office can
            // see the zone was matched and the *bracket* was the thing missing.
            zoneId: $zone?->id,
            zoneName: $zone?->name,
        );
    }

    /** The same calculation as a bare figure. Kept for callers with no place. */
    public function quoteFor(int $distanceM, int $weightKg): int
    {
        return $this->breakdownFor($distanceM, $weightKg)->cents;
    }

    /** The currency every quote is in. One install, one currency. */
    public function currency(): string
    {
        return (string) config('cargo.tariff.currency');
    }

    /**
     * Should this trip's quote be recalculated?
     *
     * Yes while it is still work in the diary: a confirmation that corrects
     * the weight, or a pin that finally gives it a distance, should change
     * what it costs. No once it has been billed — the customer has an invoice
     * with a figure on it, and moving the trip's price afterwards would leave
     * the two disagreeing with nothing to say which is right.
     *
     * Also no when somebody has entered a price by hand. A negotiated rate is
     * a decision, and re-deriving it on the next save would silently overrule
     * whoever made it.
     */
    public function shouldQuote(Trip $trip, bool $priceWasGiven): bool
    {
        return ! $priceWasGiven && ! $trip->isBilled();
    }
}
