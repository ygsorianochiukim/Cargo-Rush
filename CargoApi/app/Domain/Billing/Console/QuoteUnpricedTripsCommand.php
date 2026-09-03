<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Trip\Models\Trip;
use Illuminate\Console\Command;

/**
 * Price trips that were booked before there was a tariff.
 *
 * Every trip entered before the tariff existed carries a price of zero, which
 * is not "free" — it is "nobody asked". Left alone, those runs would show ₱0 on
 * the board, credit nothing to the monitoring sheet when delivered, and raise
 * no invoice. Exactly the shape of the gap `cargo:ledger-backfill` was written
 * for: the link only working forward reads as a bug rather than as a cutover.
 *
 * Deliberately conservative about what it touches:
 *
 *  - **Only a zero price.** Anything already priced was either quoted or
 *    negotiated, and both are somebody's decision.
 *  - **Only an unbilled trip.** A delivered run that has already put its
 *    figure on the sheet and raised its invoice is settled history; repricing
 *    it would leave the trip disagreeing with the document the customer holds.
 *
 * Which also makes it idempotent: a second run finds nothing left to do.
 *
 * A trip with no distance is quoted on the base fare and the weight alone,
 * which is less than the haul is worth. `--dry-run` lists them so the office
 * can pin those on a map first and get a real figure.
 */
class QuoteUnpricedTripsCommand extends Command
{
    protected $signature = 'cargo:trips-quote {--dry-run : List what would be priced and change nothing}';

    protected $description = 'Quote trips booked before the tariff existed';

    public function handle(PricingService $pricing): int
    {
        $unpriced = Trip::query()
            ->where('price_cents', 0)
            ->whereNull('billed_at')
            ->orderBy('scheduled_at')
            ->get();

        if ($unpriced->isEmpty()) {
            $this->info('Every trip is priced. Nothing to quote.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $unmapped = 0;

        foreach ($unpriced as $trip) {
            $quote = $pricing->quote($trip);
            $peso = number_format($quote / 100, 2);

            if ($trip->distance_total_m === 0) {
                $unmapped++;
            }

            $note = $trip->distance_total_m === 0 ? '  (no distance — base and weight only)' : '';

            $this->line("  {$trip->reference}  ₱{$peso}{$note}");

            if (! $dryRun) {
                $trip->forceFill([
                    'price_cents' => $quote,
                    'currency' => $pricing->currency(),
                ])->save();
            }
        }

        $this->info($dryRun
            ? "{$unpriced->count()} trip(s) would be quoted. Re-run without --dry-run to apply."
            : "Quoted {$unpriced->count()} trip(s).");

        if ($unmapped > 0) {
            $this->warn(
                "{$unmapped} of those have no distance, so they are priced low. "
                .'Pin both ends on the map and re-run to quote them properly.'
            );
        }

        return self::SUCCESS;
    }
}
