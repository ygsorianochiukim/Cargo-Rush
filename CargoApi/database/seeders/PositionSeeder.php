<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Position;
use Illuminate\Database\Seeder;

/**
 * The job titles an install starts with.
 *
 * `firstOrCreate`, unlike the permissions: these belong to the office the
 * moment they exist. Renaming "Office Staff" to "Admin Assistant" must survive
 * the next deployment, and the list is expected to grow — that is the point of
 * it being a table.
 *
 * ## No rates, deliberately
 *
 * Every position starts unpriced. The system has never been told what a
 * dispatcher earns in *this* firm, and two hauliers in the same yard answer
 * that differently — so seeding a figure would be inventing one, and the first
 * hire would silently be put on it.
 *
 * Unpriced means hiring into the job opens no contract and leaves the figure
 * for the office to type, which is exactly what happened before positions could
 * hold a rate at all.
 *
 * ## `drives` is the one thing seeded, and it is not about access
 *
 * It answers *does somebody in this job need a `drivers` record* — the same
 * question as *do they use the handset*. Every driver endpoint is scoped to a
 * `drivers` row, so an account with a driver's access and no such row signs in
 * and finds five empty screens.
 *
 * The helper gets it too, and should: a helper is a driver record without the
 * keys — they ride along, they are named on the trip, and the roster keeps
 * their licence.
 *
 * This used to be inferred from a `default_role_id` on the position. That link
 * is gone, because what somebody *is* and what they can *open* are different
 * questions, and conflating them means you cannot have two drivers where one
 * also keeps the books.
 */
class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = [
            ['driver', 'Driver', true, 'Holds the keys and the handset.'],
            ['helper', 'Helper', true, 'Rides along; the same record as a driver without the keys.'],
            ['dispatcher', 'Dispatcher', false, 'Books work and assigns crews.'],
            ['accountant', 'Accountant', false, 'The books and the rate card.'],
            ['treasury-officer', 'Treasury Officer', false, 'Bills, collects and files spend.'],
            ['hr-officer', 'HR Officer', false, 'The roster and the hiring.'],
            ['general-manager', 'General Manager', false, 'Runs the business.'],
            ['mechanic', 'Mechanic', false, 'Keeps the fleet on the road. Usually no login.'],
            ['office-staff', 'Office Staff', false, 'Front desk and admin.'],
        ];

        foreach ($positions as $index => [$key, $name, $drives, $description]) {
            Position::firstOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                'drives' => $drives,
                'position' => ($index + 1) * 10,
            ]);
        }
    }
}
