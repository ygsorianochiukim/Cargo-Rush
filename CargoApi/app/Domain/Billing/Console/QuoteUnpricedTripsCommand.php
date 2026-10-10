<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Console\Command;

class QuoteUnpricedTripsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:trips-quote {--dry-run : List what would be priced and change nothing}';

    protected $description = 'Quote trips that are waiting on the zone card';

    public function handle(PricingService $pricing): int
    {
        return $this->eachCompany(fn () => $this->quoteAll($pricing));
    }

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
