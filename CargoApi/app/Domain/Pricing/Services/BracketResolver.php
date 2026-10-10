<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use Illuminate\Support\Collection;

class BracketResolver
{
    /** @param iterable<PricingBracket> $brackets */
    public function pick(iterable $brackets, int $km, ?string $truckCategoryId): ?PricingBracket
    {
        $best = null;

        foreach ($brackets as $bracket) {
            if (! $bracket->appliesTo($km, $truckCategoryId)) {
                continue;
            }

            if ($best === null || $this->beats($bracket, $best)) {
                $best = $bracket;
            }
        }

        return $best;
    }

    /** @return Collection<int, PricingBracket> */
    public function candidatesFor(PricingZone $zone): Collection
    {
        return $zone->brackets->each(
            fn (PricingBracket $bracket) => $bracket->setRelation('zone', $zone),
        );
    }

    private function beats(PricingBracket $challenger, PricingBracket $held): bool
    {
        $by = $challenger->specificity() <=> $held->specificity();

        if ($by !== 0) {
            return $by > 0;
        }

        return [(int) $challenger->position, (string) $challenger->id]
            < [(int) $held->position, (string) $held->id];
    }
}
