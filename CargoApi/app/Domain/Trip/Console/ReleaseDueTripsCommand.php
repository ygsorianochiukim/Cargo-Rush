<?php

declare(strict_types=1);

namespace App\Domain\Trip\Console;

use App\Domain\Trip\Services\TripService;
use Illuminate\Console\Command;

/**
 * Release scheduled work whose time has come, and alert the driver.
 *
 * A stored status only stays true if something walks the table on a clock —
 * `scheduled_at` passing is not an event the database raises. This is that
 * something for the front of the trip's life, as `cargo:trips-overdue` is for
 * the far end of it.
 *
 * Safe to run as often as the schedule likes: releasing a trip takes it out
 * of the set this reads, so nobody is alerted twice.
 */
class ReleaseDueTripsCommand extends Command
{
    protected $signature = 'cargo:trips-release';

    protected $description = 'Move scheduled trips that are now due into the drivers\' pending queues and notify them';

    public function handle(TripService $trips): int
    {
        $released = $trips->releaseDueTrips();

        $this->info($released === 0
            ? 'Nothing due.'
            : "Released {$released} trip(s) to their drivers.");

        return self::SUCCESS;
    }
}
