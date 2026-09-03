<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Two of the trip statuses are true only as of a moment on the clock rather
| than of a write somebody made: `scheduled` becomes due, and an ETA passes.
| Nothing in the database raises either event, so these walk the table.
|
| Every minute, because "alert me when it is time" is only as accurate as the
| interval — an hourly sweep would tell a driver about an 8:00 run at 9:00.
| Both are cheap indexed reads and both no-op when there is nothing to do.
|
| In production this needs the one cron entry Laravel's scheduler runs on:
|   * * * * * cd /path/to/CargoApi && php artisan schedule:run >> /dev/null 2>&1
| Locally, `php artisan schedule:work` does the same in the foreground.
|
*/

Schedule::command('cargo:trips-release')->everyMinute()->withoutOverlapping();
Schedule::command('cargo:trips-overdue')->everyFiveMinutes()->withoutOverlapping();

// Money goes stale on the same clock. Daily rather than by the minute: a due
// date is a date, so nothing can change between one morning and the next.
Schedule::command('cargo:invoices-overdue')->dailyAt('00:05')->withoutOverlapping();
