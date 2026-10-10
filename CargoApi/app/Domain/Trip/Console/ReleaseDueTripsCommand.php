<?php

declare(strict_types=1);

namespace App\Domain\Trip\Console;

use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Services\TripService;
use Illuminate\Console\Command;

class ReleaseDueTripsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:trips-release';

    protected $description = 'Move scheduled trips that are now due into the drivers\' pending queues and notify them';

    public function handle(TripService $trips): int
    {
        return $this->eachCompany(function () use ($trips): void {
            $released = $trips->releaseDueTrips();

            $this->info($released === 0
                ? 'Nothing due.'
                : "Released {$released} trip(s) to their drivers.");
        });
    }
}
