<?php

declare(strict_types=1);

namespace App\Domain\Trip\Console;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\RoadDistance;
use Illuminate\Console\Command;

/**
 * Measure open trips on the road, and re-quote the ones the system priced.
 *
 * Every pinned trip booked before `RoadDistance` carries the straight line
 * between its pins, which is short by a band or more on most Mindanao routes —
 * CDO to Iligan sat in band B on 49 km when the truck drives about 90. Booking
 * forward is fixed; this is the same repair for what is already on the board.
 *
 * Conservative about what it touches, for the same reasons `cargo:trips-quote`
 * is:
 *
 *  - **Only unbilled trips.** A delivered run has put its figure on the sheet
 *    and raised an invoice; repricing it would disagree with the document the
 *    customer holds.
 *  - **Only distances the system worked out.** A distance the desk typed is
 *    theirs — they know the route better than any map.
 *  - **Only prices the system quoted.** A trip whose price still equals what
 *    the card said at its old distance was quoted, and is re-quoted. One that
 *    differs was negotiated, and keeps its price; only the distance moves.
 *
 * `--dry-run` lists what would change. Uses a routing call per distinct route,
 * so a large board is worth running with the dry run first to see the count.
 */
class RemeasureTripsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:trips-remeasure {--dry-run : List what would change and write nothing}';

    protected $description = 'Measure open trips on the road and re-quote the ones the system priced';

    public function handle(RoadDistance $roads, PricingService $pricing): int
    {
        return $this->eachCompany(fn () => $this->remeasure($roads, $pricing));
    }

    private function remeasure(RoadDistance $roads, PricingService $pricing): void
    {
        $trips = Trip::query()
            ->whereNull('billed_at')
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->whereNotNull('origin_lat')->whereNotNull('origin_lng')
            ->whereNotNull('destination_lat')->whereNotNull('destination_lng')
            ->where(fn ($q) => $q->whereNull('distance_source')->orWhere('distance_source', 'estimate'))
            ->orderBy('scheduled_at')
            ->get();

        if ($trips->isEmpty()) {
            $this->info('No open pinned trip needs measuring.');

            return;
        }

        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;

        foreach ($trips as $trip) {
            $wasQuoted = $trip->price_cents === $pricing->quote($trip);
            $before = (int) round($trip->distance_total_m / 1000);

            $measured = $roads->between(
                (float) $trip->origin_lat,
                (float) $trip->origin_lng,
                (float) $trip->destination_lat,
                (float) $trip->destination_lng,
            );

            $trip->forceFill([
                'distance_total_m' => $measured['metres'],
                'distance_source' => $measured['source'],
            ]);

            $quote = $wasQuoted ? $pricing->breakdown($trip) : null;
            $after = (int) round($measured['metres'] / 1000);

            $price = $quote === null
                ? '  price kept (negotiated)'
                : '  ₱'.number_format($trip->price_cents / 100, 2).' → ₱'.number_format($quote->cents / 100, 2)
                    .($quote->zoneCode === null ? '' : " (zone {$quote->zoneCode})");

            $this->line("  {$trip->reference}  {$before} km → {$after} km ({$measured['source']}){$price}");

            if ($quote !== null && $quote->cents !== $trip->price_cents) {
                $moved++;
            }

            if ($dryRun) {
                continue;
            }

            if ($quote !== null) {
                $trip->forceFill(['price_cents' => $quote->cents, ...$quote->traceColumns()]);
            }

            $trip->save();
        }

        $this->info(($dryRun ? 'Would re-measure ' : 'Re-measured ')
            ."{$trips->count()} trip(s); {$moved} price(s) ".($dryRun ? 'would change.' : 'changed.'));
    }
}
