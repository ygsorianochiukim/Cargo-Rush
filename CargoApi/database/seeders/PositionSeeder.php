<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Position;
use Illuminate\Database\Seeder;

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
