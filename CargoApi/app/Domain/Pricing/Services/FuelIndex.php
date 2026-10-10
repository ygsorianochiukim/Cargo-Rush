<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\DieselPriceRepository;

class FuelIndex
{
    public function __construct(private readonly DieselPriceRepository $prices) {}

    /** @return array{cents: int, bp: int, stepped: bool, pesos_above: int, rule: string} */
    public function surchargeFor(PricingBracket $bracket, int $cardCents, ?PricingZone $zone = null): array
    {
        $baseline = $this->baselineFor($zone);
        $current = $this->currentPriceCents();

        if ($bracket->hasDieselStep()) {
            return [
                'cents' => $bracket->dieselSurcharge($current, $baseline),
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

    public function apply(int $cents, int $adjustmentBp): int
    {
        if ($adjustmentBp === 0) {
            return $cents;
        }

        return (int) max(0, round($cents * (10_000 + $adjustmentBp) / 10_000));
    }

    public function baselineFor(?PricingZone $zone = null): int
    {
        return $zone?->diesel_baseline_cents ?? (int) config('cargo.diesel.baseline_cents');
    }

    public function currentPriceCents(): ?int
    {
        return $this->prices->current()?->price_per_litre_cents;
    }
}
