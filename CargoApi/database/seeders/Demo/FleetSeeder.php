<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The people, the units and the customers.
 *
 * Everything here matches the fixtures the two clients were built against, so
 * pointing them at the real API changes where the data comes from and nothing
 * about what appears on screen.
 */
class FleetSeeder extends Seeder
{
    public function run(): void
    {
        // The accounts are not demo data — they are how anyone signs in.
        // Called rather than duplicated, so there is one definition of them.
        $this->call(UserSeeder::class);

        $this->drivers();
        $this->vehicles();
        $this->customers();
        $this->customerAccount();
    }

    private function drivers(): void
    {
        $marcoId = User::where('email', 'marco@cargorush.ph')->value('id');

        $rows = [
            ['Marco Reyes', 'N02-14-882301', '2027-04-18', 0, 'active', 412, 96.1, $marcoId],
            ['Liza Tan', 'C03-98-114520', '2026-11-02', 1, 'active', 288, 98.4, null],
            ['Paolo Uy', 'D11-22-770914', '2026-09-30', 3, 'available', 137, 91.2, null],
            ['Ana Villar', 'N02-77-330188', '2028-01-24', 0, 'active', 502, 93.7, null],
            ['Rico Santos', 'I06-45-902117', '2027-07-11', 2, 'active', 219, 95.0, null],
            ['Grace Lim', 'P04-31-556602', '2026-08-29', 0, 'inactive', 64, 88.9, null],
            // Helpers ride the same table — they are drivers without the keys.
            ['Jun Abad', 'H01-10-110011', '2027-02-14', 0, 'available', 0, 0.0, null],
            ['Nico Diaz', 'H01-10-220022', '2027-05-20', 0, 'available', 0, 0.0, null],
            ['Rey Cruz', 'H01-10-330033', '2026-12-01', 1, 'available', 0, 0.0, null],
            ['Ben Uy', 'H01-10-440044', '2028-03-09', 0, 'available', 0, 0.0, null],
        ];

        foreach ($rows as [$name, $licence, $expiry, $violations, $status, $trips, $rate, $userId]) {
            // Matched on the account where there is one, so this fills in the
            // driver row UserSeeder already made rather than adding a second
            // Marco alongside it. Everyone else is matched on their licence.
            $key = $userId === null ? ['licence_no' => $licence] : ['user_id' => $userId];

            Driver::updateOrCreate($key, [
                'name' => $name,
                'licence_no' => $licence,
                'licence_expiry' => $expiry,
                'violations' => $violations,
                'status' => $status,
                'trips_completed' => $trips,
                'on_time_rate' => $rate,
                'user_id' => $userId,
            ]);
        }
    }

    private function vehicles(): void
    {
        $byName = Driver::query()->pluck('id', 'name');

        $rows = [
            ['NCR 4412', 'Isuzu Elf 4W', 'LTO-2024-44120', 4000, 'active', 'Marco Reyes', 184320, 190000],
            ['CEB 1180', 'Hino 300', 'LTO-2023-11801', 3500, 'available', null, 96110, 100000],
            ['DVO 7731', 'Fuso Canter', 'LTO-2022-77310', 6000, 'maintenance', null, 241870, 242000],
            ['NCR 9032', 'Isuzu NQR', 'LTO-2024-90320', 5500, 'active', 'Ana Villar', 133540, 140000],
            ['ILO 2204', 'Hyundai Mighty', 'LTO-2025-22040', 3000, 'active', 'Rico Santos', 58230, 60000],
            ['PMP 6650', 'Hino 500', 'LTO-2021-66500', 8000, 'inactive', null, 310990, 312000],
            // The six units the workbook keeps a sheet for.
            ['MAR1390', 'Isuzu Forward', 'LTO-2023-13900', 8000, 'active', 'Marco Reyes', 184320, 186000],
            ['CBS8862', 'Hino Ranger', 'LTO-2022-88620', 7500, 'active', 'Liza Tan', 152410, 158000],
            ['LAQ8325', 'Fuso Fighter', 'LTO-2021-83250', 7000, 'maintenance', null, 288730, 290000],
            ['CCE7342', 'Isuzu Giga', 'LTO-2024-73420', 9000, 'active', 'Paolo Uy', 91250, 96000],
            ['CCP9548', 'Hino 500', 'LTO-2023-95480', 8500, 'active', 'Grace Lim', 176900, 180000],
            ['CDF5211', 'Fuso Canter', 'LTO-2025-52110', 6000, 'available', null, 24110, 30000],
        ];

        foreach ($rows as [$plate, $model, $reg, $capacity, $status, $driverName, $odo, $service]) {
            Vehicle::updateOrCreate(['plate' => $plate], [
                'model' => $model,
                'registration_no' => $reg,
                'capacity_kg' => $capacity,
                'status' => $status,
                'driver_id' => $driverName === null ? null : $byName[$driverName],
                'odometer_km' => $odo,
                'next_service_km' => $service,
            ]);
        }

        $this->maintenance();
    }

    private function maintenance(): void
    {
        $plates = Vehicle::query()->pluck('id', 'plate');

        $rows = [
            ['MAR1390', 'Brake pad replacement', '2026-08-25', 190000, 'scheduled'],
            ['MAR1390', 'Oil and filter change', '2026-08-23', 186000, 'pending'],
            ['DVO 7731', 'Scheduled brake service', '2026-08-24', 242000, 'maintenance'],
        ];

        foreach ($rows as [$plate, $kind, $due, $km, $status]) {
            MaintenanceJob::updateOrCreate(
                ['vehicle_id' => $plates[$plate], 'kind' => $kind],
                ['due_at' => $due, 'next_service_km' => $km, 'status' => $status],
            );
        }
    }

    private function customers(): void
    {
        $rows = [
            ['Negros Fresh Mart', 'orders@negrosfresh.ph', 4.8, 'active'],
            ['Batangas Hardware Co.', 'logistics@bathardware.ph', 4.4, 'active'],
            ['Southline Trading', 'ops@southline.ph', 4.1, 'active'],
            ['Highland Retail', 'admin@highland.ph', 3.6, 'pending'],
            ['Metro Grocers', 'supply@metrogrocers.ph', 4.9, 'active'],
            ['Subic Freight Hub', 'desk@subicfreight.ph', 3.2, 'inactive'],
            ['Gingoog Hardware Co.', 'buyers@gingooghardware.ph', 4.3, 'active'],
        ];

        foreach ($rows as [$name, $contact, $rating, $status]) {
            Customer::updateOrCreate(['name' => $name], [
                'contact' => $contact,
                'rating' => $rating,
                'status' => $status,
            ]);
        }
    }

    /**
     * A login for one of those firms, so the customer half of `cargoApp` can
     * actually be opened in a walkthrough.
     *
     * Demo data, and only here — `UserSeeder` seeds accounts a fresh install
     * needs, and a customer account is not one of them: it would drag a
     * `customers` row in with it, and a fresh install is deliberately empty of
     * business data. Real customer logins are made with
     * `php artisan cargo:user --role=customer`.
     */
    private function customerAccount(): void
    {
        $firm = Customer::where('name', 'Negros Fresh Mart')->first();

        if ($firm === null) {
            return;
        }

        User::updateOrCreate(['email' => 'orders@negrosfresh.ph'], [
            'name' => 'Rosa Ledesma',
            'password' => Hash::make((string) env('SEED_PASSWORD', 'password')),
            'role' => Role::Customer->value,
            'customer_id' => $firm->id,
        ]);
    }
}
