<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Console\Command;

/**
 * Price the trips that are waiting on the zone card.
 *
 * A run no zone line covers is saved unpriced — a null figure and the reason —
 * and it cannot be confirmed, dispatched or billed until it has a price. Adding
 * the missing line to the Pricing card does not reach back into the diary by
 * itself; this does. Run it after the card is corrected, or leave it on the
 * schedule, and every waiting run the card now covers is quoted.
 *
 * Also picks up the older shape of the same gap: a trip entered before any
 * tariff existed carries a price of **zero** with no pricing source, which is
 * not "free" — it is "nobody asked".
 *
 * Deliberately conservative about what it touches:
 *
 *  - **Only a run with no price** (null, or that legacy zero). Anything priced
 *    was either quoted or negotiated, and both are somebody's decision — a
 *    hand-typed figure, zero included, is marked `manual` and never touched.
 *  - **Only an unbilled trip.** A delivered run that has already put its
 *    figure on the sheet and raised its invoice is settled history.
 *  - **Zone card only.** A run the card still does not cover is left unpriced
 *    with its reason refreshed, never priced at some fallback figure.
 *
 * Which also makes it idempotent: a second run finds nothing new to do.
 */
class QuoteUnpricedTripsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:trips-quote {--dry-run : List what would be priced and change nothing}';

    protected $description = 'Quote trips that are waiting on the zone card';

    public function handle(PricingService $pricing): int
    {
        return $this->eachCompany(fn () => $this->quoteAll($pricing));
    }

    /**
     * One company's unpriced trips, quoted against its own rate card.
     *
     * Per company for more than the write: `PricingService` resolves a zone and
     * a diesel baseline, and both of those are the company's own rows. A pass
     * across every firm at once would price a haul off somebody else's card.
     */
    private function quoteAll(PricingService $pricing): void
    {
        $unpriced = Trip::query()
            ->where(fn ($query) => $query
                ->whereNull('price_cents')
                ->orWhere(fn ($legacy) => $legacy->where('price_cents', 0)->whereNull('pricing_source')))
            ->whereNull('billed_at')
            ->where(fn ($query) => $query->whereNull('pricing_source')->orWhere('pricing_source', '!=', 'manual'))
            ->orderBy('scheduled_at')
            ->get();

        if ($unpriced->isEmpty()) {
            $this->info('Every trip is priced. Nothing to quote.');

            return;
        }

        $dryRun = (bool) $this->option('dry-run');
        $priced = 0;

        foreach ($unpriced as $trip) {
            $quote = $pricing->breakdown($trip);

            if ($quote->priced()) {
                $priced++;
                $this->line("  {$trip->reference}  ₱".number_format($quote->cents / 100, 2)
                    .($quote->zoneCode === null ? '' : " (zone {$quote->zoneCode})"));
            } else {
                $this->line("  {$trip->reference}  still needs a zone — {$quote->reason}");
            }

            if ($dryRun) {
                continue;
            }

            $trip->forceFill([
                'price_cents' => $quote->cents,
                'currency' => $trip->currency ?? $quote->currency,
                ...$quote->traceColumns(),
            ])->save();
        }

        $waiting = $unpriced->count() - $priced;

        $this->info($dryRun
            ? "{$priced} trip(s) would be quoted. Re-run without --dry-run to apply."
            : "Quoted {$priced} trip(s).");

        if ($waiting > 0) {
            $this->warn("{$waiting} still have no zone line. Add one on the Pricing card, or enter a price, and re-run.");
        }
    }
}
