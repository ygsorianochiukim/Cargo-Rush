<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use App\Domain\Finance\Services\FinanceService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Illuminate\Console\Command;

/**
 * Open the monitoring sheet for runs that were delivered before deliveries
 * started opening it themselves.
 *
 * Without this, the link only works forward: a business that has been running
 * trips would see an empty Trip Monitoring until its next delivery, which
 * reads exactly like the bug the link was added to fix.
 *
 * Idempotent, because it goes through the same `openDailyRow` the delivery
 * does — a day already on the sheet is found rather than opened again, and no
 * figure anybody has entered is touched.
 */
class BackfillLedgerRowsCommand extends Command
{
    protected $signature = 'cargo:ledger-backfill {--dry-run : List what would be opened and change nothing}';

    protected $description = 'Open Trip Monitoring day rows for trips that were already delivered';

    public function handle(FinanceService $finance): int
    {
        $delivered = Trip::query()
            ->with('vehicle:id,plate')
            ->where('status', StatusValue::Delivered->value)
            ->whereNotNull('vehicle_id')
            ->orderBy('updated_at')
            ->get();

        if ($delivered->isEmpty()) {
            $this->info('No delivered trips with a unit assigned. Nothing to open.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $opened = 0;

        foreach ($delivered as $trip) {
            // The day the run finished, not today — backfilling a month of
            // history onto this morning's row would be worse than no history.
            $date = $trip->deliveryLog?->delivered_at ?? $trip->updated_at;

            if ($dryRun) {
                $this->line("  {$trip->reference} → {$date->toDateString()} ({$trip->vehicle?->plate})");
                $opened++;

                continue;
            }

            $before = $finance->openDailyRow(
                vehicleId: $trip->vehicle_id,
                plate: $trip->vehicle?->plate,
                tripId: $trip->id,
                route: "{$trip->origin} → {$trip->destination}",
                date: $date,
            );

            if ($before->wasRecentlyCreated) {
                $opened++;
                $this->line("  opened {$date->toDateString()} for {$trip->reference}");
            }
        }

        $this->info($dryRun
            ? "{$opened} row(s) would be opened. Re-run without --dry-run to apply."
            : "Opened {$opened} row(s) from {$delivered->count()} delivered trip(s).");

        return self::SUCCESS;
    }
}
