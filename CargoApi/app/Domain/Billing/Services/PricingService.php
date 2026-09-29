<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Pricing\DTO\QuoteBreakdown;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Services\BracketResolver;
use App\Domain\Pricing\Services\FuelIndex;
use App\Domain\Pricing\Services\ZoneResolver;
use App\Domain\Tenancy\Support\RateBook;
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
 * ## The zone card, and nothing else
 *
 * There used to be two fallbacks under the card: the firm's plain distance
 * card (lines with no zone), and below that a flat config tariff — base plus
 * per-km plus per-kg. Both are gone, on the office's decision. A run the card
 * does not cover — 712 km against a table that stops at 600, a class of truck
 * the zone has no line for, an install with no zones yet — used to come out
 * with a figure the principal never published and the customer never agreed
 * to, and it looked exactly like a real price.
 *
 * Now it comes out **unpriced**: a null figure with a reason the desk can act
 * on ("No zone covers 712 km for a 10-wheeler"). The run can still be booked
 * — a customer's request is accepted and waits — but it cannot be confirmed,
 * dispatched, handed to a trucker, delivered or billed until somebody adds the
 * zone line or types a price (`pricing.manage`). `cargo:trips-quote` prices
 * the waiting ones once the card covers them.
 *
 * This is the only place the arithmetic exists. The ledger and the invoice
 * both read the price off the trip, so the sheet, the document and what the
 * customer was quoted cannot disagree.
 */
class PricingService
{
    /** What every refused transition says about an unpriced run. */
    public const UNPRICED = 'This run has no price yet — add a zone line on the Pricing card or enter a price.';

    public function __construct(
        private readonly ZoneResolver $zones,
        private readonly BracketResolver $brackets,
        private readonly FuelIndex $fuel,
        private readonly RateBook $rates,
    ) {}

    /** The quote for a trip, in centavos — null where no zone line covers it. */
    public function quote(Trip $trip): ?int
    {
        return $this->breakdown($trip)->cents;
    }

    /** The same figure with its reasoning attached, for storing or showing. */
    public function breakdown(Trip $trip): QuoteBreakdown
    {
        return $this->breakdownFor(
            distanceM: (int) $trip->distance_total_m,
            weightKg: (int) $trip->weight_kg,
            // What the booking asked for, not what the yard assigned. A trip is
            // quoted before it has a vehicle, and a customer who asked for a
            // brand-new unit is owed that line whatever rolls out.
            truckCategoryId: $trip->truck_category_id,
            zoneId: $trip->pricing_zone_id,
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
     * name, which is the common case — and lands in the lowest band on its base
     * alone. On a subsidy card that is the honest answer rather than a small
     * one: the shortest band is a real published rate, and the office correcting
     * the distance re-quotes it.
     *
     * `$zoneId` is the desk's pick between two bands over one distance, and it
     * is honoured only while that band still covers the run — see
     * `ZoneResolver::resolve()`.
     */
    public function breakdownFor(
        int $distanceM,
        int $weightKg,
        ?string $truckCategoryId = null,
        ?string $zoneId = null,
    ): QuoteBreakdown {
        $km = $this->kilometres($distanceM);
        $weightKg = max(0, $weightKg);

        $zone = $this->zones->resolve($zoneId, $km);

        if ($zone === null) {
            return QuoteBreakdown::unzoned(
                km: $km,
                weightKg: $weightKg,
                currency: $this->currency(),
                reason: $this->noZoneReason($km, $truckCategoryId),
            );
        }

        $bracket = $this->brackets->pick(
            $this->brackets->candidatesFor($zone),
            $km,
            $truckCategoryId,
        );

        // The band was found; the line was the thing missing. Named on the
        // quote so the office can see which band to add a line to.
        if ($bracket === null) {
            return QuoteBreakdown::unzoned(
                km: $km,
                weightKg: $weightKg,
                currency: $this->currency(),
                reason: $this->noLineReason($zone, $truckCategoryId),
                zoneId: $zone->id,
                zoneName: $zone->name,
                zoneCode: $zone->code,
                zoneBand: $zone->band(),
                zoneAlternatives: $this->alternatives($km, $zone),
            );
        }

        $card = $bracket->priceFor($km, $weightKg);
        $fuel = $this->fuel->surchargeFor($bracket, $card, $zone);

        return new QuoteBreakdown(
            cents: max(0, $card + $fuel['cents']),
            cardCents: $card,
            km: $km,
            weightKg: $weightKg,
            fuelAdjustmentBp: $fuel['bp'],
            currency: $this->currency(),
            source: 'zone',
            zoneId: $zone->id,
            zoneName: $zone->name,
            zoneCode: $zone->code,
            zoneBand: $zone->band(),
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
     * Refuse to move an unpriced run on.
     *
     * Called by every transition that commits the firm to the work or to the
     * money — confirming, dispatching, handing to a trucker, delivering,
     * billing. Booking is not one of them: a customer's request is accepted
     * unpriced and waits for the office, because refusing it would lose the
     * work rather than price it.
     *
     * Static because it reads only the trip, and the services that need it
     * (the trucker board, the desk's assign) have no other reason to hold a
     * pricing service.
     */
    public static function mustBePriced(Trip $trip): void
    {
        abort_if($trip->price_cents === null, 422, self::UNPRICED);
    }

    /** "No zone covers 712 km for a Brand New Truck." */
    private function noZoneReason(int $km, ?string $truckCategoryId): string
    {
        if (! PricingZone::query()->active()->exists()) {
            return 'There are no zones on the Pricing card yet, so nothing prices this run.';
        }

        return "No zone covers {$km} km".$this->forClass($truckCategoryId).'.';
    }

    /** "Zone B (41 – 80 km) has no line for a Brand New Truck." */
    private function noLineReason(PricingZone $zone, ?string $truckCategoryId): string
    {
        $line = $truckCategoryId === null
            ? ' has no rate line'
            : ' has no line'.$this->forClass($truckCategoryId);

        return "Zone {$zone->code} ({$zone->band()}){$line}.";
    }

    private function forClass(?string $truckCategoryId): string
    {
        $name = $truckCategoryId === null
            ? null
            : TruckCategory::query()->whereKey($truckCategoryId)->value('name');

        return $name === null ? '' : " for a {$name}";
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

    /** The currency every quote is in. One install, one currency. */
    public function currency(): string
    {
        return $this->rates->currency();
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
     * Also no once somebody has priced it by hand — on this save or any
     * earlier one. A negotiated rate is a decision, and re-deriving it on the
     * next save would silently overrule whoever made it. `pricing_source`
     * remembers it; sending the price as null hands the run back to the card.
     */
    public function shouldQuote(Trip $trip, bool $priceWasGiven): bool
    {
        return ! $priceWasGiven
            && ! $trip->isBilled()
            && $trip->pricing_source !== 'manual';
    }
}
