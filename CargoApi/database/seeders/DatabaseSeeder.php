<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh install seeds **configuration only**: the navigation, and the
 * accounts that sign in to it.
 *
 * Both are configuration rather than data. The navigation rows drive the
 * whole shell in both clients, so an empty table is an app with no sidebar
 * rather than an app waiting for its first record — and an install with no
 * account is one nobody can open to enter anything into.
 *
 * Everything else — vehicles, drivers, customers, trips, the ledger — is the
 * business's own, and is entered through the UI. Nothing here invents a truck
 * that does not exist or a route nobody drives.
 *
 * Real staff accounts are added with `php artisan cargo:user`, which asks for
 * a password rather than taking one from a file.
 *
 * Demo data for a walkthrough is `Database\Seeders\Demo\*`, run on purpose:
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\FleetSeeder"
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Access control first: the navigation is filtered by permission,
            // and a position's default role has to exist before it points at one.
            PermissionSeeder::class,
            RoleSeeder::class,
            PositionSeeder::class,
            NavigationSeeder::class,
            // Configuration for the same reason the navigation is: an expense
            // cannot be filed without a category, so an empty table is a
            // module nobody can open rather than one waiting for its first row.
            ExpenseCategorySeeder::class,
            UserSeeder::class,
        ]);

        $this->command?->info('Ready to sign in. Add the real fleet through the app, and further accounts with: php artisan cargo:user');
    }
}
