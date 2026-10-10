<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            NavigationSeeder::class,

            CompanySeeder::class,

            UserSeeder::class,
        ]);

        $this->command?->info('Ready to sign in. Add the real fleet through the app, and further accounts with: php artisan cargo:user');
    }
}
