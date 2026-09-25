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

/**
 * Trucks on the fleet that the fleet does not own.
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\HiredFleetSeeder"
 *
 * Every unit here dispatches exactly like an owned one — same board, same
 * driver, same pre-trip check, same proof of delivery, same invoice to the
 * customer. What differs is only where the money goes when the run closes, and
 * that is the whole of what this seeder is for: putting one truck on the
 * roster for each of the three ways an outside truck is paid for, so the
 * difference can be seen on the profitability page rather than described.
 *
 *   **Rented** — a flat ₱50,000 a month, and the fleet keeps every peso the
 *   truck earns. The rent is a cost of the month rather than of any run, so it
 *   is billed monthly: two months of it are raised below, through the same
 *   service the monthly job uses.
 *
 *   **Rented on a share** — no rent at all; the fleet keeps 15% of each run and
 *   the owner takes the rest. The owner is a `truckers` row, because the debt
 *   behaves exactly like a partner's and the wallet already does it.
 *
 *   **Sub-contracted** — the same arithmetic at 12%, and one difference that
 *   matters: there is somebody behind it holding a handset. That is
 *   `PartnerSeeder`'s Arnel Quibod, who sees these runs in his own app.
 *
 * ## What to look at once it has run
 *
 * The owned truck and the flat-rented one show their full income against their
 * own costs. The two share units show `owner_share_cents` on the daily sheet —
 * the same figure the owner's wallet was credited with, not a second
 * calculation — so Trip Monitoring, Profitability and the owner's statement are
 * three views of one number. A share truck without it reads as the best
 * performer on the fleet while the fleet is in fact keeping fifteen per cent.
 *
 * ## Idempotent
 *
 * Units match on the plate, owners on their phone, runs on the reference, and
 * the rent charge derives its reference from the unit and the month. Running it
 * twice changes nothing and bills nobody a second time.
 */
class HiredFleetSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany;

    /**
     * The people the fleet pays for a truck it does not own.
     *
     * No licence on either, and that is the case the column was made nullable
     * for: somebody who supplies a ten-wheeler and lets the fleet put its own
     * driver in it does not drive it and has no licence to record.
     */
    private const OWNERS = [
        ['Teresita Bacus', '0917 555 0411'],
        ['Rodolfo Emano', '0917 555 0412'],
    ];

    /**
     * plate, model, wheels, kg, status, arrangement, owner name, owner phone,
     * monthly rent in pesos, the fleet's share in basis points, driver.
     *
     * The rates are the two the business works to — 15% on a rented
     * ten-wheeler, 12% on a sub-contractor — written on each unit rather than
     * derived from the arrangement, because the rate is a negotiation with
     * whoever owns that one truck.
     */
    private const UNITS = [
        ['RNT 1001', 'Isuzu Forward', 10, 15000, 'active', 'rented', 'Delfin Hauling', '0917 555 0421', 50000, null, 'Liza Tan'],
        ['RNT 1002', 'Hino 500', 6, 8500, 'available', 'rented', 'Sierra Motors Rental', '0917 555 0422', 45000, null, null],
        ['SHR 2001', 'Fuso Fighter', 10, 16000, 'active', 'rented_share', 'Teresita Bacus', '0917 555 0411', null, 1500, 'Rico Santos'],
        ['SHR 2002', 'UD Quester', 10, 16000, 'active', 'rented_share', 'Rodolfo Emano', '0917 555 0412', null, 1500, 'Paolo Uy'],
        ['SUB 3001', 'Isuzu Giga', 10, 14000, 'active', 'subcontracted', 'Arnel Quibod', '0917 555 0306', null, 1200, 'Ana Villar'],
        /**
         * A share unit nobody has been named on.
         *
         * Half-configured on purpose. `Vehicle::sharesRevenue()` wants both an
         * arrangement *and* somebody to pay, so this one owes nothing to
         * nobody and the fleet screen shows the gap for the office to fix —
         * which is a state that happens and has to render.
         */
        ['SHR 2003', 'Hino 700', 10, 18000, 'available', 'rented_share', null, null, null, null, null],
    ];

    /**
     * The runs. reference, plate, customer, origin, destination, cargo, kg,
     * price in pesos, days from today, delivered.
     *
     * Round prices again, so the split can be checked by eye: ₱100,000 at 15%
     * is ₱15,000 to the fleet and ₱85,000 to the owner.
     */
    private const RUNS = [
        ['CR-24911', 'SHR 2001', 'Metro Grocers', 'Cagayan de Oro', 'Malaybalay', 'Sacked rice', 14000, 100000, -8, true],
        ['CR-24912', 'SHR 2001', 'Negros Fresh Mart', 'Cagayan de Oro', 'Iligan', 'Bagged feeds', 15000, 92000, -5, true],
        ['CR-24913', 'SHR 2002', 'Southline Trading', 'Cagayan de Oro', 'Butuan', 'Mixed freight', 12500, 88000, -3, true],
        ['CR-24914', 'SUB 3001', 'Batangas Hardware Co.', 'Cagayan de Oro', 'Gingoog', 'Hardware crates', 11000, 76000, -2, true],
        ['CR-24915', 'RNT 1001', 'Metro Grocers', 'Cagayan de Oro', 'Ozamiz', 'Grocery pallets', 13000, 81000, -1, true],
        // Booked and not yet run, so the board has hired units on it too.
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

    /**
     * The owners, as `truckers` rows.
     *
     * Not a table of their own, because the money owed to a ten-wheeler's owner
     * and the money owed to a partner trucker are the same debt with the same
     * lifecycle — it accrues per delivered run, it nets against what they owe
     * back, and it is settled a run at a time with a reference. That is a
     * wallet, and there is one already.
     *
     * `active` because the office has agreed terms with them, and offline
     * because there is no handset: `is_online` is a switch on a phone, and
     * neither of these two has one. It costs them nothing — a truck's owner is
     * never asked `canTakeWork()`, only paid.
     */
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
                // Only the share arrangements name somebody to pay. A flat
                // rent is a monthly bill to a firm, not a debt that accrues per
                // run, so it has an owner's name on it and no wallet.
                'owner_trucker_id' => $terms->sharesRevenue() ? ($owners[$phone] ?? null) : null,
                'driver_id' => $driver === null ? null : ($drivers[$driver] ?? null),
                'odometer_km' => random_int(40, 260) * 1000,
                'next_service_km' => random_int(270, 300) * 1000,
            ]);
        }
    }

    /**
     * Hauls on the hired units, closed the way a real one is.
     *
     * Through `TripService::complete()`, which is what makes this worth seeding
     * at all: the owner's share, the sheet's `owner_share_cents` and the
     * customer's invoice are produced by the code the app runs rather than
     * written here to look like it.
     */
    private function work(): void
    {
        $vehicles = Vehicle::query()->get()->keyBy('plate');
        $customers = Customer::query()->pluck('id', 'name');

        foreach (self::RUNS as $row) {
            [$ref, $plate, $customer, $from, $to, $cargo, $kg, $peso, $days, $delivered] = $row;

            // A closed run is left alone — see `PartnerSeeder` for why putting
            // one back to `assigned` and re-closing it would halt the seeder.
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
                // Back-dated, so the sheet and the owner's statement read as a
                // fortnight of work rather than as everything happening at once
                // this afternoon.
                Carbon::setTestNow($scheduled->copy()->addHours(7));

                $this->trips->complete($trip, new ProofData(receiver_name: 'J. Cabrera'));

                Carbon::setTestNow();
            }
        }
    }

    /**
     * Two months of rent on the flat-hired units.
     *
     * Raised through `TruckRentService`, which is what the monthly job calls,
     * and idempotent there rather than here: the reference is derived from the
     * unit and the month, so a second run finds the charge it already wrote.
     *
     * Last month and the one before, rather than this one — a month that has
     * not ended yet has not been billed, and seeding it would put a cost in the
     * current period that the real job will not raise until the 1st.
     */
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
