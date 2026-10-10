<?php

declare(strict_types=1);

namespace App\Domain\Trip\Console;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\RoadDistance;
use Illuminate\Console\Command;

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
            $wasQuoted = ! $trip->isManuallyPriced()
                && ($trip->price_cents === null || $trip->price_cents === $pricing->quote($trip));
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

            $price = match (true) {
                $quote === null => '  price kept (negotiated)',
                ! $quote->priced() => "  now needs a zone — {$quote->reason}",
                default => '  '.$this->peso($trip->price_cents).' → '.$this->peso($quote->cents)
                    .($quote->zoneCode === null ? '' : " (zone {$quote->zoneCode})"),
            };

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

    private function peso(?int $cents): string
    {
        return $cents === null ? 'unpriced' : '₱'.number_format($cents / 100, 2);
    }
}
