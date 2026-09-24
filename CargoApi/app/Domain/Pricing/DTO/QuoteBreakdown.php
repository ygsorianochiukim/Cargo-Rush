<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

/**
 * A quote, and the reasoning behind it.
 *
 * Not a `Data` subclass: the DTOs under `Shared\DTO` model an inbound payload
 * on its way to a column, and this travels the other way — it is a computed
 * answer. What it is for is being able to say *why*. Once a price comes from a
 * banded card plus a diesel step, "₱4,753" on its own is not something the desk
 * can defend to a customer, and it is not something a developer can reproduce
 * three weeks later either.
 *
 * The fuel fields are deliberately the *inputs* rather than just the result:
 * the zone's baseline, the pump price, the step, and how many whole pesos sit
 * between the two. Those four are what somebody holding the printed table needs
 * to check a figure against it, and "+₱588" alone is not.
 */
final readonly class QuoteBreakdown
{
    public function __construct(
        /** What to charge, centavos, after diesel. */
        public int $cents,
        /** The card figure before diesel — the table's `Current Price`. */
        public int $cardCents,
        public int $km,
        public int $weightKg,
        /**
         * The percentage adjustment, in basis points, where that rule applied.
         *
         * Zero on a stepped quote, and not because nothing was added — because
         * a flat peso step is not a percentage of the fare and writing one
         * here as though it were would be a number that cannot be checked.
         */
        public int $fuelAdjustmentBp,
        public string $currency,
        /**
         * Which card priced it.
         *
         *   `zone`    — a band of the rate table
         *   `card`    — the firm's plain distance card, with no band in it
         *   `tariff`  — nothing covered the run, so the configured fallback
         */
        public string $source,
        public ?string $zoneId = null,
        public ?string $zoneName = null,
        public ?string $zoneCode = null,
        public ?string $zoneBand = null,
        public ?string $bracketId = null,
        public ?string $bracketLabel = null,
        public ?string $bracketRange = null,
        /** The pump price the surcharge was worked out from, if any. */
        public ?int $dieselCents = null,
        public ?int $dieselBaselineCents = null,
        /** Pesos added per ₱1/L above the baseline. Zero where none is set. */
        public int $dieselStepCents = 0,
        /** Whole pesos a litre the pump sits above the baseline. */
        public int $dieselPesosAbove = 0,
        /** What the step added, centavos. Zero on a percentage quote. */
        public int $fuelSurchargeCents = 0,
        /**
         * Which rule priced the diesel. `step` is the table's own arithmetic,
         * `percentage` the fuel-share model, `none` a quote with no pump price
         * recorded or no adjustment to make.
         */
        public string $fuelRule = 'none',
        /**
         * The other zones whose band also covers this distance.
         *
         * A subsidy table normally has two rows over one band — A1 beside A2 —
         * and the desk's choice between them is not something a distance can
         * make. Returning the alternatives is what lets a client offer the
         * choice instead of presenting one figure as though it were the only
         * one on the card.
         *
         * @var array<int, array{id: string, code: string, name: string, band: string}>
         */
        public array $zoneAlternatives = [],
    ) {}

    /** What diesel added, in centavos. Signed — the percentage rule can discount. */
    public function fuelAdjustmentCents(): int
    {
        return $this->cents - $this->cardCents;
    }

    /** The columns a trip stores so its figure stays explainable. */
    public function traceColumns(): array
    {
        return [
            'pricing_zone_id' => $this->zoneId,
            'pricing_bracket_id' => $this->bracketId,
            'fuel_adjustment_bp' => $this->fuelAdjustmentBp,
            'fuel_surcharge_cents' => $this->fuelSurchargeCents,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cents' => $this->cents,
            'card_cents' => $this->cardCents,
            'fuel_adjustment_bp' => $this->fuelAdjustmentBp,
            'fuel_adjustment_cents' => $this->fuelAdjustmentCents(),
            'fuel_surcharge_cents' => $this->fuelSurchargeCents,
            'fuel_rule' => $this->fuelRule,
            'km' => $this->km,
            'weight_kg' => $this->weightKg,
            'currency' => $this->currency,
            'source' => $this->source,
            'zone' => $this->zoneId === null ? null : [
                'id' => $this->zoneId,
                'code' => $this->zoneCode,
                'name' => $this->zoneName,
                'band' => $this->zoneBand,
            ],
            'zone_alternatives' => $this->zoneAlternatives,
            'bracket' => $this->bracketId === null ? null : [
                'id' => $this->bracketId,
                'label' => $this->bracketLabel,
                'range' => $this->bracketRange,
            ],
            'diesel' => [
                'price_per_litre_cents' => $this->dieselCents,
                'baseline_cents' => $this->dieselBaselineCents,
                'step_cents' => $this->dieselStepCents,
                'pesos_above_baseline' => $this->dieselPesosAbove,
            ],
        ];
    }
}
