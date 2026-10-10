<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Database\Seeders\Concerns\AdoptsTrashedRows;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class HiredFleetSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany;

    private const OWNERS = [
        ['Teresita Bacus', '0917 555 0411'],
        ['Rodolfo Emano', '0917 555 0412'],
    ];

    private const UNITS = [
        ['RNT 1001', 'Isuzu Forward', 10, 15000, 'active', 'rented', 'Delfin Hauling', '0917 555 0421', 50000, null, 'Liza Tan'],
        ['RNT 1002', 'Hino 500', 6, 8500, 'available', 'rented', 'Sierra Motors Rental', '0917 555 0422', 45000, null, null],
        ['SHR 2001', 'Fuso Fighter', 10, 16000, 'active', 'rented_share', 'Teresita Bacus', '0917 555 0411', null, 1500, 'Rico Santos'],
        ['SHR 2002', 'UD Quester', 10, 16000, 'active', 'rented_share', 'Rodolfo Emano', '0917 555 0412', null, 1500, 'Paolo Uy'],
        ['SUB 3001', 'Isuzu Giga', 10, 14000, 'active', 'subcontracted', 'Arnel Quibod', '0917 555 0306', null, 1200, 'Ana Villar'],
        ['SHR 2003', 'Hino 700', 10, 18000, 'available', 'rented_share', null, null, null, null, null],
    ];

    private const RUNS = [
        ['CR-24911', 'SHR 2001', 'Metro Grocers', 'Cagayan de Oro', 'Malaybalay', 'Sacked rice', 14000, 100000, -8, true],
        ['CR-24912', 'SHR 2001', 'Negros Fresh Mart', 'Cagayan de Oro', 'Iligan', 'Bagged feeds', 15000, 92000, -5, true],
        ['CR-24913', 'SHR 2002', 'Southline Trading', 'Cagayan de Oro', 'Butuan', 'Mixed freight', 12500, 88000, -3, true],
        ['CR-24914', 'SUB 3001', 'Batangas Hardware Co.', 'Cagayan de Oro', 'Gingoog', 'Hardware crates', 11000, 76000, -2, true],
        ['CR-24915', 'RNT 1001', 'Metro Grocers', 'Cagayan de Oro', 'Ozamiz', 'Grocery pallets', 13000, 81000, -1, true],
        ['CR-24916', 'SHR 2002', 'Highland Retail', 'Cagayan de Oro', 'Valencia', 'Retail cartons', 9500, 68000, 1, false],
    ];

    public function __construct(
        private readonly TripService $trips,
        private readonly TruckRentService $rent,
    ) {}

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->owners();
            $this->units();
            $this->work();
            $this->monthlyRent();

            $this->report();
        });
    }

    private function owners(): void
    {
        foreach (self::OWNERS as [$name, $phone]) {
            $this->restoreOrCreate(Trucker::class, ['phone' => $phone], [
                'name' => $name,
                'licence_no' => null,
                'licence_expiry' => null,
                'status' => StatusValue::Active->value,
                'is_online' => false,
            ]);
        }
    }

    private function units(): void
    {
        $owners = Trucker::query()->pluck('id', 'phone');
        $drivers = Driver::query()->pluck('id', 'name');

        foreach (self::UNITS as $row) {
            [$plate, $model, $wheels, $kg, $status, $arrangement, $owner, $phone, $rent, $shareBp, $driver] = $row;

            $terms = VehicleArrangement::from($arrangement);

            $this->restoreOrCreate(Vehicle::class, ['plate' => $plate], [
                'model' => $model,
                'registration_no' => 'LTO-2025-'.preg_replace('/\D/', '', $plate),
                'capacity_kg' => $kg,
                'wheels' => $wheels,
                'status' => $status,
                'arrangement' => $terms->value,
                'owner_name' => $owner,
                'owner_contact' => $phone,
                'rent_cents' => $rent === null ? null : $rent * 100,
                'share_bp' => $shareBp,
                'owner_trucker_id' => $terms->sharesRevenue() ? ($owners[$phone] ?? null) : null,
                'driver_id' => $driver === null ? null : ($drivers[$driver] ?? null),
                'odometer_km' => random_int(40, 260) * 1000,
                'next_service_km' => random_int(270, 300) * 1000,
            ]);
        }
    }

    private function work(): void
    {
        $vehicles = Vehicle::query()->get()->keyBy('plate');
        $customers = Customer::query()->pluck('id', 'name');

        foreach (self::RUNS as $row) {
            [$ref, $plate, $customer, $from, $to, $cargo, $kg, $peso, $days, $delivered] = $row;

            if (Trip::query()->firstWhere('reference', $ref)?->isBilled()) {
                continue;
            }

            $vehicle = $vehicles[$plate] ?? null;

            if ($vehicle === null) {
                continue;
            }

            $scheduled = Carbon::now()->addDays($days)->setTime(6, 0);

            $trip = $this->restoreOrCreate(Trip::class, ['reference' => $ref], [
                'customer_id' => $customers[$customer] ?? null,
                'origin' => $from,
                'destination' => $to,
                'cargo' => $cargo,
                'weight_kg' => $kg,
                'pieces' => (int) ceil($kg / 300),
                'handling' => 'Keep dry · stack max 3 high',
                'vehicle_id' => $vehicle->getKey(),
                'driver_id' => $vehicle->driver_id,
                'status' => $delivered ? StatusValue::Assigned->value : StatusValue::Scheduled->value,
                'pickup_place' => "{$from} yard · Bay ".random_int(1, 5),
                'dropoff_place' => "{$customer}, {$to}",
                'scheduled_at' => $scheduled,
                'eta' => $scheduled->copy()->addHours(7),
                'distance_total_m' => random_int(80, 210) * 1000,
                'price_cents' => $peso * 100,
                'currency' => 'PHP',
            ]);

            if ($delivered) {
                $clock = Carbon::getTestNow();
                Carbon::setTestNow($scheduled->copy()->addHours(7));

                $this->trips->complete($trip, new ProofData(receiver_name: 'J. Cabrera'));

                Carbon::setTestNow($clock);
            }
        }
    }

    private function monthlyRent(): void
    {
        foreach ([1, 2] as $monthsBack) {
            $this->rent->chargeMonth(Carbon::now()->subMonthsNoOverflow($monthsBack));
        }
    }

    private function report(): void
    {
        $counts = Vehicle::query()
            ->whereIn('arrangement', [
                VehicleArrangement::Rented->value,
                VehicleArrangement::RentedShare->value,
                VehicleArrangement::SubContracted->value,
            ])
            ->selectRaw('arrangement, count(*) as total')
            ->groupBy('arrangement')
            ->pluck('total', 'arrangement');

        $rent = (int) Expense::query()
            ->where('reference', 'like', 'RENT %')
            ->sum('amount_cents');

        $this->command?->info(sprintf(
            '  Hired units — %d rented, %d on a share, %d sub-contracted. %s of rent raised.',
            $counts[VehicleArrangement::Rented->value] ?? 0,
            $counts[VehicleArrangement::RentedShare->value] ?? 0,
            $counts[VehicleArrangement::SubContracted->value] ?? 0,
            number_format($rent / 100, 2),
        ));
    }
}
