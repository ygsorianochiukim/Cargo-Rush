<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use App\Domain\Finance\Services\FinanceService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Console\Command;

class BackfillLedgerRowsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:ledger-backfill {--dry-run : List what would be opened and change nothing}';

    protected $description = 'Open Trip Monitoring day rows for trips that were already delivered';

    public function handle(FinanceService $finance): int
    {
        return $this->eachCompany(fn () => $this->backfill($finance));
    }

    private function backfill(FinanceService $finance): void
    {
        $delivered = Trip::query()
            ->with('vehicle:id,plate')
            ->where('status', StatusValue::Delivered->value)
            ->whereNotNull('vehicle_id')
            ->orderBy('updated_at')
            ->get();

        if ($delivered->isEmpty()) {
            $this->info('No delivered trips with a unit assigned. Nothing to open.');

            return;
        }

        $dryRun = (bool) $this->option('dry-run');
        $opened = 0;

        foreach ($delivered as $trip) {
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
                driverId: $trip->driver_id,
                helperIds: $trip->helperIds(),
            );

            if ($before->wasRecentlyCreated) {
                $opened++;
                $this->line("  opened {$date->toDateString()} for {$trip->reference}");
            }
        }

        $this->info($dryRun
            ? "{$opened} row(s) would be opened. Re-run without --dry-run to apply."
            : "Opened {$opened} row(s) from {$delivered->count()} delivered trip(s).");
    }
}
