<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Console;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Vehicle\Services\TruckRentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * `cargo:truck-rent` — bill the month's rent on every flat-hired truck.
 *
 * Run every day. It puts the current month's whole rent on the books — due on
 * the month's last day, so it shows in Payables all month — and catches up the
 * month before if that was missed. `--month` bills one named month instead.
 *
 * Runs across **every company on the install**, like the other nightly sweeps.
 * Rent is not one firm's business to trigger, and a scheduler that had to be
 * told which companies exist would silently miss the next one registered.
 *
 * Safe to run by hand and safe to run twice — the charge is keyed to the unit
 * and the month, so a second pass finds what the first wrote and does nothing.
 * That matters because the natural response to a missed cron is to run it
 * manually, and nobody should have to check first whether that double-bills.
 */
class ChargeTruckRentCommand extends Command
{
    protected $signature = 'cargo:truck-rent
        {--month= : Bill only this month, as YYYY-MM. Defaults to this month, plus last month if it was missed.}';

    protected $description = 'Raise the monthly rent expense for every truck hired at a flat fee.';

    public function handle(Tenant $tenant, TruckRentService $rent): int
    {
        $month = $this->option('month') !== null
            ? Carbon::createFromFormat('Y-m', (string) $this->option('month'))->startOfMonth()
            : null;

        $companies = Company::query()->orderBy('created_at')->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies on this install — nothing to bill.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($companies as $company) {
            // Each company's rent inside its own tenancy, so the expense, the
            // category and the sheet it lands on are all that firm's.
            $total += $tenant->use(
                $company,
                fn (): int => $month === null ? $rent->chargeDue(now()) : $rent->chargeMonth($month),
            );
        }

        $this->info(sprintf(
            '%s: raised %d rent %s.',
            $month?->format('F Y') ?? now()->format('F Y'),
            $total,
            $total === 1 ? 'charge' : 'charges',
        ));

        return self::SUCCESS;
    }
}
