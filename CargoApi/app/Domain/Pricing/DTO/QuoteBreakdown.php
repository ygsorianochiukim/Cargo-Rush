<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

/**
 * A quote, and the reasoning behind it.
 *
 * Not a `Data` subclass: the DTOs under `Shared\DTO` model an inbound payload
 * on its way to a column, and this travels the other way — it is a computed
 * answer. What it is for is being able to say *why*. Once a price comes from an
 * editable card scaled by a moving fuel index, "₱3,412" on its own is not
 * something the desk can defend to a customer, and it is not something a
 * developer can reproduce three weeks later either.
 */
final readonly class QuoteBreakdown
{
    public function __construct(
        /** What to charge, centavos, after the fuel adjustment. */
        public int $cents,
        /** The card figure before the adjustment. */
        public int $cardCents,
        public int $km,
        public int $weightKg,
        public int $fuelAdjustmentBp,
        public string $currency,
        /** `zone` when a card priced it, `tariff` when it fell back to config. */
        public string $source,
        public ?string $zoneId = null,
        public ?string $zoneName = null,
        public ?string $bracketId = null,
        public ?string $bracketLabel = null,
        public ?string $bracketRange = null,
        /** The pump price the adjustment was worked out from, if any. */
        public ?int $dieselCents = null,
        public ?int $dieselBaselineCents = null,
    ) {}

    /** What the adjustment added, in centavos. Signed. */
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
            'km' => $this->km,
            'weight_kg' => $this->weightKg,
            'currency' => $this->currency,
            'source' => $this->source,
            'zone' => $this->zoneId === null ? null : [
                'id' => $this->zoneId,
                'name' => $this->zoneName,
            ],
            'bracket' => $this->bracketId === null ? null : [
                'id' => $this->bracketId,
                'label' => $this->bracketLabel,
                'range' => $this->bracketRange,
            ],
            'diesel' => [
                'price_per_litre_cents' => $this->dieselCents,
                'baseline_cents' => $this->dieselBaselineCents,
            ],
        ];
    }
}
