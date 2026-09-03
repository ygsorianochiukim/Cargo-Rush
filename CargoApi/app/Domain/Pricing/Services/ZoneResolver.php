<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\PricingZoneRepository;

/**
 * Which card applies to this booking?
 *
 * The hard part of a place-based rate card is that a booking's destination is
 * free text. Somebody at the desk types what the caller said — "Davao",
 * "Davao City", "Bajada, Davao City", "dvo" — and none of those is an id. So
 * a zone carries the strings it answers to, and this matches against them.
 *
 * Deliberately a substring match rather than anything cleverer. Fuzzy matching
 * on place names is how a run to Tagum gets priced as a run to Digos: the
 * failure mode of being too strict is a trip that falls back to the config
 * tariff, which is visible and correctable, and the failure mode of being too
 * loose is a wrong invoice nobody notices.
 */
class ZoneResolver
{
    public function __construct(private readonly PricingZoneRepository $zones) {}

    /**
     * The zone for a booking, or null when no card claims it.
     *
     * The destination is tried first and the origin only as a fallback, because
     * the card is about where the goods are going. A run that starts in a zone
     * and ends outside it is priced by where it ends — that is the longer leg
     * and the one the customer is buying.
     */
    public function forTrip(?string $destination, ?string $origin = null): ?PricingZone
    {
        return $this->match($destination) ?? $this->match($origin);
    }

    /**
     * The best zone for one free-text place, or null.
     *
     * Longest matching term wins. Where a zone answers to "davao" and another
     * to "davao del norte", a destination naming the province has to reach the
     * province's card — and it contains both terms, so the tie has to break on
     * specificity rather than on which row came back first.
     */
    public function match(?string $place): ?PricingZone
    {
        $needle = $this->normalise($place);

        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach ($this->zones->active() as $zone) {
            foreach ($zone->matchTerms() as $term) {
                $normalised = $this->normalise($term);

                if ($normalised === '' || ! str_contains($needle, $normalised)) {
                    continue;
                }

                if (mb_strlen($normalised) > $bestLength) {
                    $best = $zone;
                    $bestLength = mb_strlen($normalised);
                }
            }
        }

        return $best;
    }

    /**
     * Lower case, punctuation to spaces, runs of space collapsed.
     *
     * "Bajada, Davao City." and "bajada davao city" have to be the same string
     * before a substring test means anything.
     */
    private function normalise(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
