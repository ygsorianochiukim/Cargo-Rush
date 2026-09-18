<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use Illuminate\Support\Collection;

/**
 * Which line of the rate card prices this run.
 *
 * Two kinds of card feed into this, and a quote may be answered by either.
 *
 * **A banded card** — a subsidy table. `ZoneResolver` has already picked the
 * zone from the distance, so what is left to choose is the class of truck: the
 * zone's general line, or the line for the class the booking asked for.
 *
 * **The zoneless card** — the firm's plain distance card, "450 km is ₱5,000",
 * where the line carries its own kilometres. A firm with no table behind it
 * prices entirely from these and never opens the zone editor.
 *
 * This picks the most specific line that covers the run:
 *
 *     zone + category   →   "A1, brand new truck"
 *     zone only         →   "A1"                    ← the table's own column
 *     category only     →   "Freezer, anywhere, 0–50 km"
 *     neither           →   "0–50 km"               ← the plain distance card
 *
 * ## Why most-specific rather than first-match
 *
 * Because the alternative is a card you have to reason about row order to
 * read. An office that adds a brand-new-truck rate to band A1 has not stopped
 * meaning A1's general figure for everything else, and it should not have to
 * think about where in the list the new row landed. Specificity is a property
 * of the row; position is a property of the table, and only one of those is
 * what somebody meant.
 *
 * Ties — two lines equally specific and equally covering — break on `position`
 * then on id, so the answer is at least stable. That is a card with a genuine
 * overlap in it, which is the office's to fix, and a quote that flickered
 * between two prices would hide it.
 */
class BracketResolver
{
    /**
     * The line that prices a run, or null when the card does not cover it.
     *
     * Null is a real answer and the caller falls back to the config tariff: a
     * table that stops at 600 km asked a run of 700 has no figure for it, and
     * inventing one would be worse than saying the card missed.
     *
     * @param  iterable<PricingBracket>  $brackets  every line available to this run
     */
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

    /**
     * The lines a run may be priced from: this zone's, plus the zoneless card.
     *
     * Both together rather than one or the other, which is what makes the
     * zoneless card a genuine fallback rather than dead rows. A firm whose
     * subsidy table stops at 600 km can still have a plain "600 km and beyond"
     * line, and a run past the end of the table reaches it instead of the
     * config tariff.
     *
     * The zone is set on each of its own lines before they are returned, so
     * `covers()` can read the band off it without a query per line — the one
     * place in this module where a relation is populated by hand, because the
     * alternative is an N+1 inside the loop that prices every quote.
     *
     * @return Collection<int, PricingBracket>
     */
    public function candidatesFor(?PricingZone $zone): Collection
    {
        $general = PricingBracket::query()
            ->with('truckCategory')
            ->whereNull('zone_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($zone === null) {
            return $general;
        }

        $banded = $zone->brackets->each(
            fn (PricingBracket $bracket) => $bracket->setRelation('zone', $zone),
        );

        return $banded->concat($general);
    }

    /**
     * Is the challenger a better match than the one held?
     *
     * More specific wins. Equal specificity falls back to `position` and then
     * the id, so an overlapping card at least answers the same way twice.
     */
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
