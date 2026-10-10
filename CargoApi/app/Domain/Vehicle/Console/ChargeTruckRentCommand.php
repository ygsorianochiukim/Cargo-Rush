<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Console;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Vehicle\Services\TruckRentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

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
