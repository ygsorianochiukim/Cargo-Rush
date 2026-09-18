<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Pricing\DTO\QuoteBreakdown;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Services\BracketResolver;
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
 * So a trip is quoted at booking, and quoted the way the trade's own rate
 * tables are written:
 *
 *   1. The **distance** picks the zone — a band, `A1` being 1–40 km and `O`
 *      561–600 (`ZoneResolver`). Where two bands overlap, the desk's pick on
 *      the trip wins and the table's order decides otherwise.
 *   2. The **class of truck** picks the line inside that zone, which holds the
 *      base and the diesel step (`BracketResolver`).
 *   3. Today's **pump price** adds the step — so many pesos for every ₱1/L
 *      above the card's baseline (`FuelIndex`).
 *
 * That third step is the change worth reading twice. A subsidy table does not
 * scale by a percentage; it adds a flat amount per band per peso of diesel, and
 * tabulates the result so the desk can read a figure off. Band A1 at ₱85/L
 * against a ₱43 baseline is 4,165 + 14 × 42 = ₱4,753, and ₱4,753 is what the
 * printed table says in its ₱85 column. Getting that to agree to the peso is
 * the entire point of the exercise.
 *
 * ## The destination no longer prices anything
 *
 * It used to: a booking's destination was matched as a substring against per-
 * town aliases, and that chose the card. It is gone, and the reason is in the
 * table rather than in a preference — A1 and A2 are both 1–40 km at different
 * money, so no destination string can tell them apart, and a matcher would
 * have answered A1 for every A2 run without anything in the data admitting it.
 *
 * With the fallbacks that keep the whole thing safe. A distance past the end of
 * the table falls to the firm's plain distance card if it has one, and to
 * `config/cargo.php` if it does not —
 *
 *     price = base + (per_km * km) + (per_kg * kg)   floored at `minimum`
 *
 * so a 700 km run against a card that stops at 600 produces a defensible
 * figure the trace marks as `tariff`, rather than a zero.
 *
 * This is the only place either arithmetic exists. The ledger and the invoice
 * both read the price off the trip, so the sheet, the document and what the
 * customer was quoted cannot disagree.
 */
class PricingService
{
    public function __construct(
        private readonly ZoneResolver $zones,
        private readonly BracketResolver $brackets,
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
        $distanceM = (int) $trip->distance_total_m;

        return $this->breakdownFor(
            distanceM: $distanceM,
            weightKg: (int) $trip->weight_kg,
            // What the booking asked for, not what the yard assigned. A trip is
            // quoted before it has a vehicle, and a customer who asked for a
            // brand-new unit is owed that line whatever rolls out.
            truckCategoryId: $trip->truck_category_id,
            zoneId: $this->bandStillOn($trip->pricing_zone_id, $this->kilometres($distanceM)),
        );
    }

    /**
     * The trip's band, if the trip is still in it.
     *
     * `trips.pricing_zone_id` does double duty: it is where the desk records a
     * choice between two bands over the same kilometres, and it is where the
     * trace of what actually priced the trip is written. One column for both
     * on purpose — the band somebody picked and the band that priced the run
     * are one fact, and two columns would be two answers to "which band is
     * this?" with nothing to say which the invoice used.
     *
     * The cost of that is staleness, and this is where it is paid. A trip
     * booked without a distance is quoted in the lowest band and the trace
     * records it; a dispatcher then pins the route at 200 km, and honouring
     * the stored band would re-quote a 200 km haul at the 1–40 km rate for
     * ever. So a stored band applies only while the distance is still inside
     * it, and a run that has moved out of its band is re-banded from the
     * distance.
     *
     * A desk that meant A2 and then corrected the distance out of A2's range
     * has to pick again. That is the honest outcome: the choice it made was
     * between two bands that no longer apply.
     */
    private function bandStillOn(?string $zoneId, int $km): ?string
    {
        $zone = $this->zones->byId($zoneId);

        return $zone !== null && $zone->covers($km) ? $zone->id : null;
    }

    /**
     * The same calculation from raw figures, for a quote before a row exists.
     *
     * Distance is metres here and kilometres on the card, rounded up: a 1.2 km
     * run is charged as two, the way a fare is, rather than as one plus a
     * fraction of a centavo nobody can invoice.
     *
     * An unmapped trip has no distance — booked over the phone against a town
     * name, which is the common case — and lands in the lowest band on its base
     * alone. On a subsidy card that is the honest answer rather than a small
     * one: the shortest band is a real published rate, and the office correcting
     * the distance re-quotes it.
     */
    public function breakdownFor(
        int $distanceM,
        int $weightKg,
        ?string $truckCategoryId = null,
        ?string $zoneId = null,
    ): QuoteBreakdown {
        $km = $this->kilometres($distanceM);
        $weightKg = max(0, $weightKg);

        /**
         * The band, from the distance — or the one the desk picked.
         *
         * Null where no band covers the run, which is a card that stops short
         * rather than an error. The zoneless distance card gets the next go.
         */
        $zone = $this->zones->resolve($zoneId, $km);

        $bracket = $this->brackets->pick(
            $this->brackets->candidatesFor($zone),
            $km,
            $truckCategoryId,
        );

        // No line covers this run — a table that ends at 600 km asked about
        // 700, or no card at all. Falling back to the tariff is the honest
        // answer: it is a real price, it is not the card's, and the trace
        // columns say so.
        if ($bracket === null) {
            return $this->fromTariff($km, $weightKg, $zone);
        }

        // The zone that actually priced it, which is not necessarily the one
        // the distance landed in: a run priced off the plain distance card was
        // not priced by band A1, and the trace must not claim it was.
        $zone = $bracket->zone_id === null ? null : ($bracket->zone ?? $zone);

        $card = $bracket->priceFor($km, $weightKg);
        $fuel = $this->fuel->surchargeFor($bracket, $card, $zone);

        return new QuoteBreakdown(
            cents: max(0, $card + $fuel['cents']),
            cardCents: $card,
            km: $km,
            weightKg: $weightKg,
            fuelAdjustmentBp: $fuel['bp'],
            currency: $this->currency(),
            source: $zone === null ? 'card' : 'zone',
            zoneId: $zone?->id,
            zoneName: $zone?->name,
            zoneCode: $zone?->code,
            zoneBand: $zone?->band(),
            bracketId: $bracket->id,
            bracketLabel: $bracket->label,
            bracketRange: $bracket->range(),
            dieselCents: $this->fuel->currentPriceCents(),
            dieselBaselineCents: $this->fuel->baselineFor($zone),
            dieselStepCents: $bracket->diesel_step_cents,
            dieselPesosAbove: $fuel['pesos_above'],
            fuelSurchargeCents: $fuel['stepped'] ? $fuel['cents'] : 0,
            fuelRule: $fuel['rule'],
            zoneAlternatives: $this->alternatives($km, $zone),
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
            // see the band was found and the *line* was the thing missing.
            zoneId: $zone?->id,
            zoneName: $zone?->name,
            zoneCode: $zone?->code,
            zoneBand: $zone?->band(),
            zoneAlternatives: $this->alternatives($km, $zone),
        );
    }

    /**
     * The other bands that cover this distance, for a client to offer.
     *
     * The zone that priced the run is left out — it is already named on the
     * quote, and repeating it in a list headed "or instead" is how a client
     * ends up rendering A1 twice.
     *
     * @return array<int, array{id: string, code: string, name: string, band: string}>
     */
    private function alternatives(int $km, ?PricingZone $priced): array
    {
        return $this->zones->covering($km)
            ->reject(fn (PricingZone $zone): bool => $zone->id === $priced?->id)
            ->map(fn (PricingZone $zone): array => [
                'id' => $zone->id,
                'code' => $zone->code,
                'name' => $zone->name,
                'band' => $zone->band(),
            ])
            ->values()
            ->all();
    }

    /**
     * Metres to whole kilometres, rounded up.
     *
     * A 1.2 km run is two, the way a fare is, rather than one plus a fraction
     * of a centavo nobody can put on an invoice. In one place because the band
     * a run falls in and the price it is charged have to be worked out from
     * the same number.
     */
    private function kilometres(int $distanceM): int
    {
        return (int) ceil(max(0, $distanceM) / 1000);
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
