<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use Illuminate\Support\Collection;

/**
 * Which line of a zone prices this run.
 *
 * `ZoneResolver` has already picked the zone from the distance, so what is left
 * to choose is the class of truck: the zone's general line, or the line for the
 * class the booking asked for.
 *
 *     zone + category   →   "A1, brand new truck"
 *     zone only         →   "A1"                    ← the table's own column
 *
 * Only the zone's own lines are candidates. Lines with no zone — the old plain
 * distance card — used to be offered alongside as a fallback, and no longer
 * are: a trip is priced off the zone card or not at all (see
 * `PricingService`). Any such rows an install still holds are left in the
 * table untouched and simply never read for a quote.
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
     * The line that prices a run, or null when the zone has none for it.
     *
     * Null is a real answer and the caller leaves the run unpriced: a zone
     * with no line for a brand-new truck has no figure for one, and inventing
     * one would be worse than saying the card missed.
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
     * The lines a run may be priced from: this zone's, and only this zone's.
     *
     * The zone is set on each of its own lines before they are returned, so
     * `covers()` can read the band off it without a query per line — the one
     * place in this module where a relation is populated by hand, because the
     * alternative is an N+1 inside the loop that prices every quote.
     *
     * @return Collection<int, PricingBracket>
     */
    public function candidatesFor(PricingZone $zone): Collection
    {
        return $zone->brackets->each(
            fn (PricingBracket $bracket) => $bracket->setRelation('zone', $zone),
        );
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
