<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\ContractService;
use App\Domain\Identity\Models\Position;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Concerns\AdoptsTrashedRows;
use Database\Seeders\Concerns\NeedsAZoneCard;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Database\Seeders\Concerns\UpsertsByDay;
use Illuminate\Database\Seeder;

class PayrollSeeder extends Seeder
{
    use AdoptsTrashedRows, NeedsAZoneCard, SeedsIntoACompany, UpsertsByDay;

    public function __construct(private readonly PricingService $pricing) {}

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->positions();
            $this->staff();
            $this->truckSheet();
            $this->ensureZoneCard();
            $this->trips();

            $this->report();
        });
    }

    private function trips(): void
    {
        $marco = Employee::query()->where('employee_no', 'DEMO-03')->first();

        if ($marco?->driver_id === null) {
            return;
        }

        $period = PayrollCalendar::for($this->seedCompany())->justClosed();

        $vehicle = $this->restoreOrCreate(Vehicle::class,
            ['plate' => 'DEMO 0001'],
            [
                'model' => 'Isuzu Elf 4W',
                'registration_no' => 'DEMO-LTO-0001',
                'capacity_kg' => 4000,
                'status' => 'active',
                'driver_id' => $marco->driver_id,
                'odometer_km' => 120_000,
            ],
        );

        Truck::query()->where('label', 'Demo Truck A')->update(['vehicle_id' => $vehicle->id]);

        $customer = $this->restoreOrCreate(Customer::class,
            ['name' => 'Demo Trading Co.'],
            ['contact' => '0917 555 0900', 'address' => 'Davao City', 'status' => 'active'],
        );

        $jun = Employee::query()->where('employee_no', 'DEMO-04')->first();

        $runs = [
            [0, 'Davao City', 'Tagum', 'Rice · 120 sacks', 6_000, $marco->driver_id, $jun?->driver_id],
            [1, 'Davao City', 'Digos', 'Feeds · 80 sacks', 4_000, $marco->driver_id, null],
            [2, 'Davao City', 'Mati', 'Cement · 200 bags', 8_000, $jun?->driver_id, null],
            [3, 'Tagum', 'Davao City', 'Dry goods', 5_200, $marco->driver_id, $jun?->driver_id],
            [5, 'Davao City', 'Nabunturan', 'Hardware', 3_800, $jun?->driver_id, null],
            [6, 'Davao City', 'Panabo', 'Assorted cargo', 3_400, $marco->driver_id, null],
            [8, 'Panabo', 'Davao City', 'Copra', 7_100, $jun?->driver_id, $marco->driver_id],
        ];

        foreach ($runs as $n => [$offset, $from, $to, $cargo, $kg, $driverId, $helperId]) {
            $date = $period->start->copy()->addDays($offset);

            if ($date->gt($period->end)) {
                continue;
            }

            $trip = $this->restoreOrCreate(Trip::class,
                ['reference' => 'DEMO-TR-'.str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT)],
                [
                    'origin' => $from,
                    'destination' => $to,
                    'cargo' => $cargo,
                    'weight_kg' => $kg,
                    'pieces' => (int) ceil($kg / 300),
                    'driver_id' => $driverId,
                    'vehicle_id' => $vehicle->id,
                    'customer_id' => $customer->id,
                    'status' => 'delivered',
                    'scheduled_at' => $date->copy()->setTime(7, 0),
                    'distance_total_m' => random_int(60, 180) * 1000,
                ],
            );

            $trip->setHelpers($helperId === null ? [] : [$helperId]);

            $trip->forceFill([
                'price_cents' => $this->pricing->quote($trip),
                'currency' => 'PHP',
            ])->save();

            LedgerEntry::query()
                ->where('truck_id', Truck::query()->where('label', 'Demo Truck A')->value('id'))
                ->whereDate('date', $date->toDateString())
                ->whereNull('trip_id')
                ->update(['trip_id' => $trip->id, 'customer_id' => $customer->id]);
        }
    }

    private function positions(): void
    {
        $rows = [
            ['Admin', PayBasis::Monthly, [1_200_000, 1_350_000, 1_500_000], 'Office administration.'],
            ['Treasury Officer', PayBasis::Monthly, [1_800_000, 2_000_000, 2_200_000], 'Keeps the cash and the books.'],
            ['Driver', PayBasis::PerTrip, [150_000, 150_000, 150_000], 'Paid for each haul delivered.'],
        ];

        foreach ($rows as $order => [$name, $basis, $tiers, $description]) {
            $existing = Position::query()->where('name', $name)->first();

            if ($existing !== null && $existing->hasRateCard()) {
                $this->command?->warn(sprintf(
                    '  "%s" already pays %s — left alone.',
                    $name,
                    $existing->paySummary(),
                ));

                continue;
            }

            Position::updateOrCreate(
                ['name' => $name],
                [
                    'key' => $existing?->key ?? str($name)->slug()->value(),
                    'description' => $existing?->description ?? $description,
                    'pay_basis' => $basis->value,
                    'trainee_amount_cents' => $tiers[0],
                    'probationary_amount_cents' => $tiers[1],
                    'regular_amount_cents' => $tiers[2],
                    'position' => $existing?->position ?? $order,
                    'status' => 'active',
                ],
            );
        }
    }

    private function staff(): void
    {
        $jobs = Position::query()->pluck('id', 'name');

        $rows = [
            ['DEMO-01', 'Elena', 'Bautista', 'Admin', null, null],
            ['DEMO-02', 'Rosa', 'Lim', 'Treasury Officer', null, null],
            ['DEMO-03', 'Marco', 'Villanueva', 'Driver', 'DEMO-LIC-0003', '2029-08-31'],
            ['DEMO-04', 'Jun', 'Santos', 'Driver', 'DEMO-LIC-0004', '2028-11-30'],
        ];

        foreach ($rows as [$number, $first, $last, $job, $licence, $expiry]) {
            $position = Position::query()->find($jobs[$job] ?? null);

            $driverId = null;

            if ($licence !== null) {
                $driverId = $this->restoreOrCreate(Driver::class,
                    ['licence_no' => $licence],
                    [
                        'name' => "{$first} {$last}",
                        'licence_expiry' => $expiry,
                        'status' => 'available',
                    ],
                )->id;
            }

            $employee = $this->restoreOrCreate(Employee::class,
                ['employee_no' => $number],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'position' => $job,
                    'position_id' => $position?->id,
                    'department' => $licence === null ? 'Administration' : 'Operations',
                    'employment_type' => 'regular',
                    'status' => 'active',
                    'hired_on' => '2026-01-05',
                    'contact' => '0917 555 0'.substr($number, -3),
                    'driver_id' => $driverId,
                ],
            );

            if ($position !== null && $employee->contractOn() === null) {
                app(ContractService::class)->openFromPosition($employee, $position, $employee->hired_on);
            }
        }
    }

    private function truckSheet(): void
    {
        $period = PayrollCalendar::for($this->seedCompany())->justClosed();

        $marco = Driver::query()->where('licence_no', 'DEMO-LIC-0003')->value('id');
        $jun = Driver::query()->where('licence_no', 'DEMO-LIC-0004')->value('id');

        $truck = Truck::updateOrCreate(
            ['label' => 'Demo Truck A'],
            ['plate' => 'DEMO 0001', 'position' => 900],
        );

        $second = Truck::updateOrCreate(
            ['label' => 'Demo Truck B'],
            ['plate' => 'DEMO 0002', 'position' => 901],
        );

        $days = [
            [0, $truck->id, $marco, $jun, 120_000, 60_000],
            [1, $truck->id, $marco, null, 95_000, 0],
            [2, $second->id, $jun, null, 110_000, 0],
            [3, $truck->id, $marco, $jun, 140_000, 70_000],
            [5, $second->id, $jun, null, 85_000, 0],
            [6, $truck->id, $marco, null, 105_000, 0],
            [8, $second->id, $jun, $marco, 125_000, 55_000],
        ];

        foreach ($days as [$offset, $truckId, $driverId, $helperId, $driverPay, $helperPay]) {
            $date = $period->start->copy()->addDays($offset);

            if ($date->gt($period->end)) {
                continue;
            }

            $row = $this->upsertOn(
                LedgerEntry::class,
                ['truck_id' => $truckId],
                'date',
                $date,
                [
                    'driver_id' => $driverId,
                    'driver_salary_cents' => $driverPay,
                    'trip_income_cents' => $driverPay * 6,
                    'fuel_cents' => $driverPay,
                    'route' => 'Demo run',
                ],
            );

            app(FinanceService::class)->syncHelperLines($row, $helperId === null
                ? []
                : [['driver_id' => $helperId, 'salary_cents' => $helperPay]]);
        }
    }

    private function report(): void
    {
        $period = PayrollCalendar::for($this->seedCompany())->justClosed();

        $this->command?->info('Payroll demo ready.');
        $this->command?->line('  Admin ₱15,000/month · Treasury ₱22,000/month · two drivers on per-trip');
        $this->command?->line("  Open Payroll and build the run for {$period->label()}.");
        $this->command?->line(sprintf(
            '  Period: %s to %s · pay date %s',
            $period->start->toDateString(),
            $period->end->toDateString(),
            $period->cutoff()->toDateString(),
        ));
    }
}
