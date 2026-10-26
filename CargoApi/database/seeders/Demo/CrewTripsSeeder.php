<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\DTO\DriverData;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\ContractService;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleDriverAssignment;
use Database\Seeders\Concerns\AdoptsTrashedRows;
use Database\Seeders\Concerns\NeedsAZoneCard;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CrewTripsSeeder extends Seeder
{
    use AdoptsTrashedRows, NeedsAZoneCard, SeedsIntoACompany;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly ContractService $contracts,
        private readonly DriverService $drivers,
    ) {}

    public function run(): void
    {
        $this->intoCompany(function (Company $company): void {
            $this->ensureZoneCard();

            $calendar = PayrollCalendar::for($company);
            $periods = $this->periods($calendar);

            $customer = $this->restoreOrCreate(Customer::class,
                ['name' => 'Demo Trading Co.'],
                ['contact' => '0917 555 0900', 'address' => 'Davao City', 'status' => 'active'],
            );

            $marco = $this->crew('Marco Villanueva', 'DEMO-LIC-0001', 'driver');
            $jun = $this->crew('Jun Santos', 'DEMO-LIC-0002', 'driver');
            $pedro = $this->crew('Pedro Santos', null, 'helper', 50_000);
            $rey = $this->subcontractorDriver();

            $owned = $this->restoreOrCreate(Vehicle::class,
                ['plate' => 'DEMO 0002'],
                [
                    'model' => 'Isuzu Forward', 'registration_no' => 'DEMO-LTO-0002', 'capacity_kg' => 8000,
                    'status' => 'active', 'arrangement' => 'owned', 'driver_id' => $marco->id,
                ],
            );

            $rented = $this->restoreOrCreate(Vehicle::class,
                ['plate' => 'DEMO 0004'],
                [
                    'model' => 'Fuso Fighter', 'registration_no' => 'DEMO-LTO-0004', 'capacity_kg' => 12_000,
                    'status' => 'active', 'arrangement' => 'rented', 'rent_cents' => 5_000_000,
                    'owner_name' => 'R. Dela Cruz', 'owner_contact' => '0917 555 0404', 'driver_id' => $jun->id,
                ],
            );

            $owner = Trucker::query()->where('name', 'Ortega Trucking')->first()
                ?? Trucker::create(['name' => 'Ortega Trucking', 'business_name' => 'Ortega Trucking', 'phone' => '0917 555 0505', 'status' => 'active']);

            $sub = $this->restoreOrCreate(Vehicle::class,
                ['plate' => 'DEMO 0003'],
                [
                    'model' => 'Hino 500', 'registration_no' => 'DEMO-LTO-0003', 'capacity_kg' => 15_000,
                    'status' => 'active', 'arrangement' => 'subcontracted', 'owner_trucker_id' => $owner->id,
                    'driver_id' => $rey->id,
                ],
            );

            $previous = $periods[0];
            $current = $periods[1];

            $this->hold($owned, $marco->id, $previous->start->copy()->subMonth());
            $this->hold($sub, $rey->id, $previous->start->copy()->subMonth());
            $this->hold($rented, $jun->id, $current->start->copy()->addDays(3));
            $this->splitBackfilledHolds($current);

            $n = 0;

            foreach ($periods as $period) {
                $plan = [
                    [0, $marco->id, $owned->id, [$pedro->id], 'Davao City', 'Tagum City', 'Rice · 120 sacks', 6_000],
                    [2, $marco->id, $owned->id, [], 'Davao City', 'Digos City', 'Feeds · 80 sacks', 4_000],
                    [5, $marco->id, $owned->id, [$pedro->id], 'Tagum City', 'Davao City', 'Dry goods', 5_200],
                    [1, $jun->id, $rented->id, [], 'Davao City', 'Mati City', 'Cement · 200 bags', 8_000],
                    [4, $jun->id, $rented->id, [$pedro->id], 'Davao City', 'Nabunturan', 'Hardware', 3_800],
                    [7, $jun->id, $rented->id, [], 'Panabo City', 'Davao City', 'Copra', 7_100],
                    [3, $rey->id, $sub->id, [], 'Davao City', 'General Santos', 'Frozen goods', 9_000],
                    [6, $rey->id, $sub->id, [], 'General Santos', 'Davao City', 'Bananas', 9_500],
                    [8, $jun->id, $owned->id, [], 'Davao City', 'Panabo City', 'Assorted cargo', 3_400],
                ];

                foreach ($plan as [$offset, $driverId, $vehicleId, $helperIds, $from, $to, $cargo, $kg]) {
                    $date = $period->start->copy()->addDays($offset)->setTime(7, 0);

                    if ($date->gt($period->end->copy()->endOfDay()) || $date->isFuture()) {
                        continue;
                    }

                    $n++;

                    $trip = $this->restoreOrCreate(Trip::class,
                        ['reference' => 'DEMO-CT-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT)],
                        [
                            'origin' => $from,
                            'destination' => $to,
                            'cargo' => $cargo,
                            'weight_kg' => $kg,
                            'pieces' => (int) ceil($kg / 300),
                            'driver_id' => $driverId,
                            'vehicle_id' => $vehicleId,
                            'customer_id' => $customer->id,
                            'status' => 'delivered',
                            'scheduled_at' => $date,
                            'distance_total_m' => (60 + ($n * 17) % 120) * 1000,
                            'driver_allowance_cents' => 50_000,
                            'helper_allowance_cents' => $helperIds === [] ? 0 : 30_000,
                        ],
                    );

                    $trip->setHelpers($helperIds);

                    if ($trip->price_cents === null) {
                        $trip->forceFill(['price_cents' => $this->pricing->quote($trip), 'currency' => 'PHP'])->save();
                    }

                    DeliveryLog::query()->updateOrCreate(
                        ['trip_id' => $trip->id],
                        ['status' => 'delivered', 'delivered_at' => $date->copy()->setTime(16, 30), 'receiver_name' => 'Warehouse clerk'],
                    );
                }
            }

            $this->command?->info(sprintf(
                'Crew trips ready: %d trips across %s and %s. Jun holds %s (rented) from %s; Rey Ortega drives %s for Ortega Trucking.',
                $n,
                $previous->label(),
                $current->label(),
                $rented->plate,
                $current->start->copy()->addDays(3)->format('j M'),
                $sub->plate,
            ));
        });
    }

    /** @return array{0: PayPeriod, 1: PayPeriod} */
    private function periods(PayrollCalendar $calendar): array
    {
        $closed = $calendar->justClosed();
        $month = $closed->start->copy()->subDay();

        $before = collect($calendar->inMonth((int) $closed->start->year, (int) $closed->start->month))
            ->merge($calendar->inMonth((int) $month->year, (int) $month->month))
            ->filter(static fn (PayPeriod $period): bool => $period->end->lt($closed->start))
            ->sortByDesc(static fn (PayPeriod $period) => $period->start->timestamp)
            ->first();

        return [$before ?? $closed, $closed];
    }

    private function crew(string $name, ?string $licence, string $role, int $rateCents = 150_000): Driver
    {
        $driver = Driver::query()->where('name', $name)->where('role', $role)->first();

        if ($driver === null) {
            $driver = $this->drivers->create(DriverData::fromArray([
                'name' => $name,
                'role' => $role,
                'employment' => 'company',
                'contact' => '0917 555 0'.random_int(100, 999),
                'licence_no' => $licence,
                'licence_expiry' => $licence === null ? null : now()->addYears(3)->toDateString(),
                'status' => 'available',
            ]));
        }

        $employee = Employee::query()->where('driver_id', $driver->id)->first();

        if ($employee !== null && $employee->contractOn(now()) === null) {
            $this->contracts->open($employee, PayBasis::PerTrip, $rateCents, now()->subYear());
        }

        return $driver->refresh();
    }

    private function subcontractorDriver(): Driver
    {
        return Driver::query()->where('name', 'Rey Ortega')->first()
            ?? $this->drivers->create(DriverData::fromArray([
                'name' => 'Rey Ortega',
                'role' => 'driver',
                'employment' => 'subcontractor',
                'contact' => '0917 555 0303',
                'licence_no' => 'DEMO-SUB-0001',
                'licence_expiry' => now()->addYears(3)->toDateString(),
                'status' => 'available',
            ]));
    }

    private function hold(Vehicle $vehicle, string $driverId, Carbon $from): void
    {
        VehicleDriverAssignment::query()->where('vehicle_id', $vehicle->id)->delete();

        VehicleDriverAssignment::create([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driverId,
            'from_date' => $from->toDateString(),
        ]);

        if ($vehicle->driver_id !== $driverId) {
            $vehicle->forceFill(['driver_id' => $driverId])->save();
        }
    }

    private function splitBackfilledHolds(PayPeriod $current): void
    {
        VehicleDriverAssignment::query()
            ->open()
            ->onHiredTrucks()
            ->whereDate('from_date', '>=', now()->toDateString())
            ->whereNotIn('vehicle_id', Vehicle::query()->where('plate', 'like', 'DEMO %')->select('id'))
            ->update(['from_date' => $current->start->copy()->addDays(4)->toDateString()]);
    }
}
