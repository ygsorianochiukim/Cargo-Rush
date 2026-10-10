<?php

declare(strict_types=1);

namespace App\Domain\Trip\Console;

use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Services\TripService;
use Illuminate\Console\Command;

class ReconcileOverdueTripsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:trips-overdue';

    protected $description = 'Mark trips past their ETA as overdue';

    public function handle(TripService $trips): int
    {
        return $this->eachCompany(function () use ($trips): void {
            $flagged = $trips->reconcileOverdue();

            $this->info($flagged === 0 ? 'Nothing overdue.' : "Flagged {$flagged} trip(s) overdue.");
        });
    }
}
