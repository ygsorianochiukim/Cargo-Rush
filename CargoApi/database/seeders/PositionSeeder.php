<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The job titles an install starts with.
 *
 * `firstOrCreate`, unlike the permissions: these belong to the office the
 * moment they exist. Renaming "Office Staff" to "Admin Assistant" must survive
 * the next deployment, and the list is expected to grow — that is the point of
 * it being a table.
 *
 * Each carries the role somebody in that job normally gets, which is what makes
 * registering a new hire one choice instead of two. It stays a default: the
 * account names its own role, so a driver who also keeps the books can be given
 * the accountant's access without inventing a job title for it.
 */
class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'key');

        $positions = [
            ['driver', 'Driver', 'driver', 'Holds the keys and the handset.'],
            ['helper', 'Helper', 'driver', 'Rides along; the same record as a driver without the keys.'],
            ['dispatcher', 'Dispatcher', 'dispatcher', 'Books work and assigns crews.'],
            ['accountant', 'Accountant', 'accountant', 'The books and the rate card.'],
            ['treasury-officer', 'Treasury Officer', 'treasury', 'Bills, collects and files spend.'],
            ['hr-officer', 'HR Officer', 'hr-officer', 'The roster and the hiring.'],
            ['general-manager', 'General Manager', 'general-manager', 'Runs the business.'],
            ['mechanic', 'Mechanic', null, 'Keeps the fleet on the road. Usually no login.'],
            ['office-staff', 'Office Staff', null, 'Front desk and admin.'],
        ];

        foreach ($positions as $index => [$key, $name, $roleKey, $description]) {
            Position::firstOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                // Null where the job does not normally sign in at all, which is
                // honest: a mechanic with an account is the exception.
                'default_role_id' => $roleKey === null ? null : ($roles[$roleKey] ?? null),
                'position' => ($index + 1) * 10,
            ]);
        }
    }
}
