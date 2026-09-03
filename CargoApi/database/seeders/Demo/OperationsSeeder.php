<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Driver\Models\Driver;
use App\Domain\Gps\Models\GpsPing;
use App\Domain\Incident\Models\Incident;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Trips and the four records that hang off them.
 *
 * Dates are relative to today rather than pinned to a fixed day, so the
 * dashboard, the GPS view and the overdue badge all still have something to
 * show whenever this is run.
 */
class OperationsSeeder extends Seeder
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * reference, origin, destination, cargo, kg, driver, helper, plate,
     * customer, status, hours from now to departure, hours to ETA.
     */
    private const TRIPS = [
        ['CR-24817', 'Manila', 'Batangas', 'Dry goods, 12 pallets', 3200, 'Marco Reyes', 'Jun Abad', 'NCR 4412', 'Southline Trading', 'in_transit', -6, 2],
        ['CR-24818', 'Cebu', 'Dumaguete', 'Chilled produce', 1450, 'Liza Tan', null, 'CEB 1180', 'Negros Fresh Mart', 'delivered', -30, -23],
        ['CR-24819', 'Davao', 'General Santos', 'Construction steel', 5100, 'Paolo Uy', 'Nico Diaz', 'DVO 7731', 'Metro Grocers', 'assigned', 18, null],
        // A customer's own request, exactly as the portal files one: a route,
        // a load and a weight, and no crew, no unit and no agreed time until
        // somebody at the desk confirms it. `pending` means nobody has decided
        // about this yet, which is why the demo needs one sitting there.
        ['CR-24823', 'Bacolod', 'Iloilo', 'Chilled produce, 8 crates', 1800, null, null, null, 'Negros Fresh Mart', 'pending', 26, null],
        ['CR-24820', 'Manila', 'Baguio', 'Retail cartons', 2750, 'Ana Villar', 'Rey Cruz', 'NCR 9032', 'Highland Retail', 'overdue', -34, -26],
        ['CR-24821', 'Iloilo', 'Bacolod', 'Packaged food', 890, 'Rico Santos', null, 'ILO 2204', 'Metro Grocers', 'assigned', 2, 8],
        ['CR-24822', 'Clark', 'Subic', 'Electronics', 4300, 'Grace Lim', 'Ben Uy', 'PMP 6650', 'Subic Freight Hub', 'scheduled', 22, 26],
        ['CR-24815', 'Manila', 'Batangas', 'Hardware crates', 2100, 'Marco Reyes', 'Jun Abad', 'NCR 4412', 'Batangas Hardware Co.', 'delivered', -54, -48],
        ['CR-24812', 'Manila', 'Bulacan', 'Grocery pallets', 3600, 'Grace Lim', 'Ben Uy', 'PMP 6650', 'Metro Grocers', 'delivered', -78, -72],
        ['CR-24811', 'Subic', 'Clark', 'Mixed freight', 1800, 'Paolo Uy', null, 'DVO 7731', 'Subic Freight Hub', 'cancelled', -96, null],
    ];

    public function run(): void
    {
        $drivers = Driver::query()->pluck('id', 'name');
        $vehicles = Vehicle::query()->pluck('id', 'plate');
        $customers = Customer::query()->pluck('id', 'name');

        foreach (self::TRIPS as $row) {
            [$ref, $from, $to, $cargo, $kg, $driver, $helper, $plate, $customer, $status, $depart, $eta] = $row;

            $scheduled = Carbon::now()->addHours($depart);

            $trip = Trip::updateOrCreate(['reference' => $ref], [
                'origin' => $from,
                'destination' => $to,
                'cargo' => $cargo,
                'weight_kg' => $kg,
                'pieces' => (int) ceil($kg / 300),
                'handling' => 'Keep dry · stack max 3 high',
                'driver_id' => $drivers[$driver] ?? null,
                'helper_id' => $helper === null ? null : ($drivers[$helper] ?? null),
                'vehicle_id' => $vehicles[$plate] ?? null,
                'customer_id' => $customers[$customer] ?? null,
                'status' => $status,
                'pickup_place' => "{$from} depot · Bay ".random_int(1, 5),
                'dropoff_place' => "{$customer}, {$to}",
                'scheduled_at' => $scheduled,
                'eta' => $eta === null ? null : Carbon::now()->addHours($eta),
                'distance_total_m' => random_int(60, 220) * 1000,
            ]);

            // Quoted from the same tariff a real booking goes through, so the
            // demo board shows the prices a walkthrough is about to talk about
            // rather than a column of zeros. Written after the create because
            // the quote reads the distance, which is set in it.
            $trip->forceFill([
                'price_cents' => $this->pricing->quote($trip),
                'currency' => $this->pricing->currency(),
            ])->save();

            $this->records($trip, $status, $scheduled);
        }

        $this->incidents($drivers, $vehicles);
    }

    /**
     * The dispatch record, delivery log and GPS trail that belong with a trip
     * in that state. A delivered trip with no proof of delivery, or one in
     * transit with no position, would be a gap the UI has to apologise for.
     */
    private function records(Trip $trip, string $status, Carbon $scheduled): void
    {
        $left = in_array($status, ['in_transit', 'delivered', 'overdue'], true);

        if ($left) {
            DispatchRecord::updateOrCreate(['trip_id' => $trip->id], [
                'vehicle_id' => $trip->vehicle_id,
                'dispatched_at' => $scheduled->copy()->addMinutes(random_int(2, 20)),
                'location' => $trip->pickup_place,
                'arrived_at' => $status === 'delivered' ? $trip->eta : null,
                'status' => $status,
            ]);
        }

        DeliveryLog::updateOrCreate(['trip_id' => $trip->id], [
            'delivered_at' => $status === 'delivered' ? $trip->eta : null,
            // `pod_ref` is deliberately absent: the model assigns the next one
            // in the `POD-` series when `delivered_at` is set, exactly as it
            // does on a real hand-off. Seeding a made-up reference would put
            // numbers in the table that the live series then hands out again.
            'receiver_name' => $status === 'delivered' ? 'L. Tan' : null,
            'status' => $status === 'delivered' ? 'delivered' : ($status === 'cancelled' ? 'cancelled' : $status),
        ]);

        if (in_array($status, ['in_transit', 'overdue'], true)) {
            $this->trail($trip);
        }
    }

    /**
     * A short history rather than a single point, because average speed is
     * derived from distance over time and one ping cannot express that.
     */
    private function trail(Trip $trip): void
    {
        $trip->pings()->delete();

        $places = ['SLEX · Sto. Tomas exit', 'Kennon Road km 21', 'Dumangas port queue', 'Cebu South Road'];
        $steps = 6;

        for ($i = 1; $i <= $steps; $i++) {
            $progress = (int) round($i / $steps * random_int(45, 80));

            GpsPing::create([
                'trip_id' => $trip->id,
                'location' => $places[array_rand($places)],
                'speed_kph' => random_int(0, 78),
                'heading' => ['North', 'South', 'East', 'West'][array_rand([0, 1, 2, 3])],
                'progress_pct' => $progress,
                'distance_done_m' => (int) round($trip->distance_total_m * $progress / 100),
                'recorded_at' => now()->subMinutes(($steps - $i) * 12),
            ]);
        }
    }

    /**
     * @param  Collection<string, string>  $drivers
     * @param  Collection<string, string>  $vehicles
     */
    private function incidents($drivers, $vehicles): void
    {
        $rows = [
            ['INC-0231', 'Traffic hold', 'Kennon Road km 21', -9, 'Ana Villar', 'NCR 9032', 'pending'],
            ['INC-0230', 'Tyre blowout', 'SLEX km 58', -28, 'Marco Reyes', 'NCR 4412', 'active'],
            ['INC-0229', 'Cargo damage', 'Dumaguete depot', -52, 'Liza Tan', 'CEB 1180', 'delivered'],
            ['INC-0228', 'Overheating', 'Davao bypass', -76, 'Paolo Uy', 'DVO 7731', 'maintenance'],
        ];

        foreach ($rows as [$ref, $kind, $place, $hours, $driver, $plate, $status]) {
            Incident::updateOrCreate(['reference' => $ref], [
                'kind' => $kind,
                'place' => $place,
                'occurred_at' => Carbon::now()->addHours($hours),
                'driver_id' => $drivers[$driver] ?? null,
                'vehicle_id' => $vehicles[$plate] ?? null,
                'status' => $status,
            ]);
        }
    }
}
