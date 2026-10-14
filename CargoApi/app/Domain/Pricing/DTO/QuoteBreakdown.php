<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

final readonly class QuoteBreakdown
{
    public function __construct(
        public ?int $cents,
        public ?int $cardCents,
        public int $km,
        public int $weightKg,
        public int $fuelAdjustmentBp,
        public string $currency,
        public string $source,
        public ?string $zoneId = null,
        public ?string $zoneName = null,
        public ?string $zoneCode = null,
        public ?string $zoneBand = null,
        public ?string $bracketId = null,
        public ?string $bracketLabel = null,
        public ?string $bracketRange = null,
        public ?int $dieselCents = null,
        public ?int $dieselBaselineCents = null,
        public int $dieselStepCents = 0,
        public int $dieselPesosAbove = 0,
        public int $fuelSurchargeCents = 0,
        public string $fuelRule = 'none',
        /** @var array<int, array{id: string, code: string, name: string, band: string}> */
        public array $zoneAlternatives = [],
        public ?string $reason = null,
        public ?int $cbm = null,
        public ?int $cardCbm = null,
        public bool $zonePinned = false,
    ) {}

    /** @param array<int, array{id: string, code: string, name: string, band: string}> $zoneAlternatives */
    public static function unzoned(
        int $km,
        int $weightKg,
        string $currency,
        string $reason,
        ?string $zoneId = null,
        ?string $zoneName = null,
        ?string $zoneCode = null,
        ?string $zoneBand = null,
        array $zoneAlternatives = [],
        bool $zonePinned = false,
    ): self {
        return new self(
            cents: null,
            cardCents: null,
            km: $km,
            weightKg: $weightKg,
            fuelAdjustmentBp: 0,
            currency: $currency,
            source: 'unzoned',
            zoneId: $zoneId,
            zoneName: $zoneName,
            zoneCode: $zoneCode,
            zoneBand: $zoneBand,
            zoneAlternatives: $zoneAlternatives,
            reason: $reason,
            zonePinned: $zonePinned,
        );
    }

    public function priced(): bool
    {
        return $this->cents !== null;
    }

    public function fuelAdjustmentCents(): int
    {
        return $this->priced() ? $this->cents - (int) $this->cardCents : 0;
    }

    public function traceColumns(): array
    {
        return [
            'pricing_zone_id' => $this->zoneId,
            'pricing_bracket_id' => $this->bracketId,
            'fuel_adjustment_bp' => $this->fuelAdjustmentBp,
            'fuel_surcharge_cents' => $this->fuelSurchargeCents,
            'pricing_source' => $this->source,
            'pricing_note' => $this->reason,
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
            'needs_zone' => ! $this->priced(),
            'reason' => $this->reason,
            'zone' => $this->zoneId === null ? null : [
                'id' => $this->zoneId,
                'code' => $this->zoneCode,
                'name' => $this->zoneName,
                'band' => $this->zoneBand,
            ],
            'zone_pinned' => $this->zonePinned,
            'zone_alternatives' => $this->zoneAlternatives,
            'bracket' => $this->bracketId === null ? null : [
                'id' => $this->bracketId,
                'label' => $this->bracketLabel,
                'range' => $this->bracketRange,
            ],
            'volume' => $this->cbm === null ? null : [
                'cbm' => $this->cbm,
                'card_cbm' => $this->cardCbm,
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
