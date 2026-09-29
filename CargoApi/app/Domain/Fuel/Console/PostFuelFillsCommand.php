<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Console;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Services\FuelPostingService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put the fills logged before fills posted themselves onto their sheets.
 *
 * Totals do not need it: an active fill no row carries is still counted, on
 * its own, by every roll-up. What it fixes is the sheet — the Fuel column on
 * Trip Monitoring reads what the unit really burned, and the column locks
 * against a typed figure — so a firm with history sees the same thing a new
 * one does.
 *
 * Idempotent. It only touches active fills with nothing posted, and posting
 * goes through `FuelPostingService`, which records what it put where; a second
 * run finds nothing left to do.
 *
 * ## `--absorb-typed`: the one-off dedupe
 *
 * Before `/fuel` was the single source, an office often logged a receipt there
 * **and** typed the same figure onto the sheet, and the two were added. With
 * the flag, the figure somebody typed on that truck's day is taken to be the
 * same receipts: it is reduced by the fill's amount (never below zero) before
 * the fill posts, so the day's Fuel column ends up at the larger of the two
 * rather than their sum. Without it, the typed figure is left alone and the
 * fill is added — which is exactly what the totals already counted.
 *
 * Run with `--dry-run` first; it lists every fill and what would move.
 */
class PostFuelFillsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:post-fuel-fills
        {--dry-run : List what would be posted and change nothing}
        {--absorb-typed : Treat a figure typed on the same truck and day as the same receipt}';

    protected $description = 'Post active fuel fills logged at /fuel onto their trucks\' daily sheet rows';

    public function handle(FuelPostingService $posting): int
    {
        return $this->eachCompany(fn () => $this->post($posting));
    }

    private function post(FuelPostingService $posting): void
    {
        $dryRun = (bool) $this->option('dry-run');
        $absorb = (bool) $this->option('absorb-typed');

        $trucks = Truck::query()->whereNotNull('vehicle_id')->pluck('id', 'vehicle_id');

        $fills = FuelRecord::query()
            ->where('status', StatusValue::Active->value)
            ->where('posted_cents', 0)
            ->whereNotNull('vehicle_id')
            ->where('amount_cents', '>', 0)
            ->orderBy('logged_at')
            ->get()
            ->filter(static fn (FuelRecord $fill): bool => isset($trucks[$fill->vehicle_id]));

        $posted = 0;
        $absorbed = 0;

        foreach ($fills as $fill) {
            $truckId = $trucks[$fill->vehicle_id];
            $day = $fill->logged_at->toDateString();

            $row = LedgerEntry::query()->where('truck_id', $truckId)->whereDate('date', $day)->first();

            // What is on the row that no fill put there — the typed part.
            $typed = $row === null ? 0 : max(0, (int) $row->fuel_cents - (int) FuelRecord::query()
                ->where('posted_truck_id', $truckId)
                ->whereDate('posted_on', $day)
                ->sum('posted_cents'));

            $take = $absorb ? min($typed, (int) $fill->amount_cents) : 0;

            $this->line(sprintf(
                '  %s %s ₱%s%s',
                $day,
                $fill->receipt_no ?? $fill->getKey(),
                number_format($fill->amount_cents / 100, 2),
                $take > 0 ? sprintf(' (absorbs ₱%s typed)', number_format($take / 100, 2)) : '',
            ));

            if ($dryRun) {
                $posted++;
                $absorbed += $take;

                continue;
            }

            DB::transaction(function () use ($posting, $fill, $row, $take): void {
                if ($take > 0 && $row !== null) {
                    $row->update(['fuel_cents' => max(0, (int) $row->fuel_cents - $take)]);
                }

                $posting->sync($fill);
            });

            $posted++;
            $absorbed += $take;
        }

        $this->info(sprintf(
            '%s %d fill(s)%s.',
            $dryRun ? 'Would post' : 'Posted',
            $posted,
            $absorb ? sprintf(', absorbing ₱%s typed on the same days', number_format($absorbed / 100, 2)) : '',
        ));
    }
}
