<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Pricing\DTO\QuoteBreakdown;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Services\BracketResolver;
use App\Domain\Pricing\Services\FuelIndex;
use App\Domain\Pricing\Services\ZoneResolver;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;

class PricingService
{
    public const UNPRICED = 'This run has no price yet — add a zone line on the Pricing card or enter a price.';

    public function __construct(
        private readonly ZoneResolver $zones,
        private readonly BracketResolver $brackets,
        private readonly FuelIndex $fuel,
        private readonly RateBook $rates,
    ) {}

    public function quote(Trip $trip): ?int
    {
        return $this->breakdown($trip)->cents;
    }

    public function breakdown(Trip $trip): QuoteBreakdown
    {
        return $this->breakdownFor(
            distanceM: (int) $trip->distance_total_m,
            weightKg: (int) $trip->weight_kg,
            truckCategoryId: $trip->truck_category_id,
            zoneId: $trip->pricing_zone_id,
            pinned: (bool) $trip->zone_pinned,
        );
    }

    public function breakdownFor(
        int $distanceM,
        int $weightKg,
        ?string $truckCategoryId = null,
        ?string $zoneId = null,
        bool $pinned = false,
    ): QuoteBreakdown {
        $km = $this->kilometres($distanceM);
        $weightKg = max(0, $weightKg);

        $zone = $this->zones->resolve($zoneId, $km, $pinned);
        $pinned = $pinned && $zone !== null && $zone->id === $zoneId;
        $cardKm = $pinned ? $zone->clamp($km) : $km;

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
            $cardKm,
            $truckCategoryId,
        );

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
                zonePinned: $pinned,
            );
        }

        $card = $bracket->priceFor($cardKm, $weightKg);
        $fuel = $this->fuel->surchargeFor($bracket, $card, $zone);
        $step = (int) $bracket->diesel_step_cents;
        $cbm = $this->scaledCbm($bracket, $truckCategoryId);
        $cardCbm = $this->cardCbm();

        if ($cbm !== null) {
            $total = $this->scale($card + $fuel['cents'], $cbm, $cardCbm);
            $card = $this->scale($card, $cbm, $cardCbm);
            $fuel['cents'] = $total - $card;
            $step = $this->scale($step, $cbm, $cardCbm);
        }

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
            dieselStepCents: $step,
            dieselPesosAbove: $fuel['pesos_above'],
            fuelSurchargeCents: $fuel['stepped'] ? $fuel['cents'] : 0,
            fuelRule: $fuel['rule'],
            zoneAlternatives: $this->alternatives($km, $zone),
            cbm: $cbm,
            cardCbm: $cbm === null ? null : $cardCbm,
            zonePinned: $pinned,
        );
    }

    private function scaledCbm(PricingBracket $bracket, ?string $truckCategoryId): ?int
    {
        if ($bracket->truck_category_id !== null || $truckCategoryId === null) {
            return null;
        }

        $cbm = TruckCategory::query()->whereKey($truckCategoryId)->value('cbm');

        return $cbm === null || (int) $cbm === $this->cardCbm() ? null : (int) $cbm;
    }

    public function cardCbm(): int
    {
        return max(1, (int) config('cargo.card_cbm', 28));
    }

    private function scale(int $cents, int $cbm, int $cardCbm): int
    {
        return (int) round($cents * $cbm / $cardCbm);
    }

    public static function mustBePriced(Trip $trip): void
    {
        abort_if($trip->price_cents === null, 422, self::UNPRICED);
    }

    private function noZoneReason(int $km, ?string $truckCategoryId): string
    {
        if (! PricingZone::query()->active()->exists()) {
            return 'There are no zones on the Pricing card yet, so nothing prices this run.';
        }

        return "No zone covers {$km} km".$this->forClass($truckCategoryId).'.';
    }

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

    /** @return array<int, array{id: string, code: string, name: string, band: string}> */
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

    private function kilometres(int $distanceM): int
    {
        return (int) ceil(max(0, $distanceM) / 1000);
    }

    public function currency(): string
    {
        return $this->rates->currency();
    }
}
