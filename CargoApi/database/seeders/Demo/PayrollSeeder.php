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
use Database\Seeders\Concerns\SeedsIntoACompany;
use Database\Seeders\Concerns\UpsertsByDay;
use Illuminate\Database\Seeder;

/**
 * A payroll you can run, five minutes after a fresh install.
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\PayrollSeeder"
 *
 * Deliberately **not** part of `DatabaseSeeder`: a live install starts empty,
 * and nobody wants to explain to a client why their roster contains a treasury
 * officer they have never employed.
 *
 * ## What it sets up, and why each piece is there
 *
 * Three jobs, priced on the position so the roster inherits them:
 *
 *   **Admin — ₱15,000 a month.** The worked example. On a twice-monthly cutoff
 *   this is ₱7,500 on each payslip, and the whole point of seeding it is that
 *   you can see the halving happen rather than take it on trust.
 *
 *   **Treasury Officer — ₱22,000 a month.** A second salary, so the run has
 *   more than one row and the register totals mean something.
 *
 *   **Driver — per trip.** No figure on the job at all: what a driver earns is
 *   whatever the truck sheet recorded that cutoff. Which is why this seeder
 *   also writes the sheet.
 *
 * ## The truck sheet is the interesting half
 *
 * A per-trip driver on an empty sheet is paid nothing, and a demo where the
 * driver's payslip reads ₱0.00 teaches the wrong lesson. So seven days of
 * driver and helper pay are written **inside the period that has just closed**,
 * attributed to the two drivers — which is what makes their payslips sum to a
 * real figure the office can check against the sheet.
 *
 * The dates are worked out from the firm's **own** calendar rather than
 * hardcoded. A seeder that wrote the 1st to the 15th would put the rows outside
 * the period on any install that cuts off on the 10th and the 25th, and the
 * demo would quietly show every driver earning nothing.
 *
 * ## Idempotent
 *
 * Everything matches on a natural key — a position name, an employee number, a
 * licence, a truck and a date. Running it twice tops the data up rather than
 * doubling the roster, which matters because the obvious thing to do after a
 * confusing payroll run is to run the seeder again.
 */
class PayrollSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany, UpsertsByDay;

    public function __construct(private readonly PricingService $pricing) {}

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->positions();
            $this->staff();
            $this->truckSheet();
            $this->trips();

            $this->report();
        });
    }

    /**
     * Four delivered runs for the first driver, on the days he was paid for.
     *
     * The truck sheet above is the thing payroll actually reads, and an office
     * can type it by hand — which is what the second driver's rows demonstrate.
     * These trips show the other half: a delivered run **opens the day's row
     * itself** and stamps who was in the cab, so the sheet fills in as work
     * happens rather than at the end of the fortnight.
     *
     * What a trip still does not do is say what the run *cost*. It carries no
     * driver fee and never will — a trip knows what the customer was charged,
     * not what the crew was paid — so the fee stays the office's to enter, and
     * the rows above are where it lands.
     *
     * Dated onto days the sheet already has, deliberately: two sources writing
     * separate rows for the same truck and day would double a driver's pay,
     * which is exactly the failure this pairing is meant to show cannot happen.
     */
    private function trips(): void
    {
        $marco = Employee::query()->where('employee_no', 'DEMO-03')->first();

        if ($marco?->driver_id === null) {
            return;
        }

        $period = PayrollCalendar::for($this->seedCompany())->justClosed();

        /**
         * A unit of its own, tied to Demo Truck A.
         *
         * `FinanceService` finds a workbook truck by `vehicle_id`, so linking
         * them is what keeps a delivered trip landing on the demo's sheet
         * instead of opening a brand new "Truck 3" beside it.
         */
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

        /**
         * One haul per day of the sheet, crewed exactly as the sheet crews it.
         *
         * The offsets and the pairings mirror `truckSheet()` deliberately.
         * Per-trip pay is the contract rate times the hauls delivered, so a
         * sheet day with no trip behind it pays its crew nothing — and the two
         * lists drifting apart would show up as a driver quietly earning less
         * than the days beside their name.
         *
         * Five hauls each: Marco drives four and rides as helper on the last,
         * Jun drives three and rides on two. Same count, so the demo's two
         * drivers come out on the same money from different work.
         */
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

            // Priced through the same rate card a real booking goes through, so
            // the board shows figures rather than a column of zeros.
            $trip->forceFill([
                'price_cents' => $this->pricing->quote($trip),
                'currency' => 'PHP',
            ])->save();

            /**
             * The row this run belongs to, pointed back at the trip.
             *
             * `updateOrCreate` on truck and date, which is the sheet's own key:
             * the day already exists with the driver's fee on it, so this adds
             * the trip reference rather than a second row. A day is one row per
             * unit however many runs went into it — the workbook's rule, and
             * what stops four trips paying a driver four times.
             */
            LedgerEntry::query()
                ->where('truck_id', Truck::query()->where('label', 'Demo Truck A')->value('id'))
                ->whereDate('date', $date->toDateString())
                ->whereNull('trip_id')
                ->update(['trip_id' => $trip->id, 'customer_id' => $customer->id]);
        }
    }

    /**
     * Three jobs with their pay on them.
     *
     * The pay lives on the **position** so that hiring somebody into it opens
     * them a contract on the figure — which is the flow this seeder exists to
     * demonstrate, and the reason the employees below name a position rather
     * than a salary.
     *
     * Three figures each, because a job is priced by the tier somebody is
     * engaged at. The demo staff are all regular, so the regular column is what
     * their contracts open on.
     */
    private function positions(): void
    {
        // Trainee, probationary, regular.
        $rows = [
            ['Admin', PayBasis::Monthly, [1_200_000, 1_350_000, 1_500_000], 'Office administration.'],
            ['Treasury Officer', PayBasis::Monthly, [1_800_000, 2_000_000, 2_200_000], 'Keeps the cash and the books.'],
            // A rate for each haul delivered, the same on every tier: a trainee
            // driver moves the same load as a regular one.
            ['Driver', PayBasis::PerTrip, [150_000, 150_000, 150_000], 'Paid for each haul delivered.'],
        ];

        foreach ($rows as $order => [$name, $basis, $tiers, $description]) {
            $existing = Position::query()->where('name', $name)->first();

            /**
             * A job the office has already priced is left exactly as it is.
             *
             * This is the one place the seeder could do real harm on a company
             * that is already in use: silently repricing a live "Driver" would
             * change what every future hire is offered, and the change would
             * look like somebody's decision rather than a demo's side effect.
             *
             * The cost is that the walkthrough then shows *their* figure rather
             * than ₱15,000 — so it says so rather than leaving the numbers
             * looking wrong.
             */
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

    /**
     * Four people: two on a salary, two driving.
     *
     * The salary is **not** written here. It is left out of the attributes
     * entirely so `Employee::create` takes it from the position — the same path
     * a real hire goes through, which is worth demonstrating rather than
     * shortcutting. See `EmployeeService::withPositionSalary()` for the rule;
     * this seeder writes the columns directly, so it copies them itself.
     */
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

            // The driving jobs get a `drivers` row, because that is what the
            // truck sheet names and what payroll looks their work up under. A
            // per-trip employee without one is paid nothing, correctly and
            // confusingly.
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

            // Opened off the job, exactly as hiring through the form would have
            // done — and only where there is not one already, so re-running the
            // seeder on a company in use cannot append a second contract and
            // silently restate somebody's pay.
            if ($position !== null && $employee->contractOn() === null) {
                app(ContractService::class)->openFromPosition($employee, $position, $employee->hired_on);
            }
        }
    }

    /**
     * Seven days of the sheet, inside the period that has just closed.
     *
     * Dated off the firm's **own** calendar rather than hardcoded. A seeder
     * that wrote the 1st to the 15th would drop every row outside the period on
     * an install cutting off on the 10th and the 25th, and the demo would show
     * both drivers earning nothing — which looks like a broken feature rather
     * than a badly chosen date.
     *
     * The figures are deliberately uneven. A driver on the same ₱1,200 every
     * day makes a sum that is obviously seven times something; varying them
     * means the payslip total is a figure you have to actually add up, which is
     * the thing worth checking.
     */
    private function truckSheet(): void
    {
        $period = PayrollCalendar::for($this->seedCompany())->justClosed();

        $marco = Driver::query()->where('licence_no', 'DEMO-LIC-0003')->value('id');
        $jun = Driver::query()->where('licence_no', 'DEMO-LIC-0004')->value('id');

        /**
         * The demo gets **its own units**, and that is not cosmetic.
         *
         * The obvious labels — "Truck 1", "Truck 2" — are the ones a real
         * install already has, and a day's row is keyed on the truck and the
         * date. Writing into those would overwrite a real day's trip income and
         * fuel with demo figures, in a table the Profitability and Quarterly
         * Summary pages read. That is the one genuinely destructive thing this
         * seeder could do to a company already in use, so it does not share a
         * truck with one.
         *
         * Named so they are obvious in a list and easy to remove afterwards —
         * see `cargo:demo-payroll --remove`.
         */
        $truck = Truck::updateOrCreate(
            ['label' => 'Demo Truck A'],
            ['plate' => 'DEMO 0001', 'position' => 900],
        );

        $second = Truck::updateOrCreate(
            ['label' => 'Demo Truck B'],
            ['plate' => 'DEMO 0002', 'position' => 901],
        );

        /** Day offset into the period, truck, driver, helper, driver pay, helper pay. */
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

            // Only inside the period. A short February can make the last of
            // these fall past the cutoff, and a row dated outside is a row that
            // counts toward nobody — which would be a confusing demo rather
            // than a wrong one.
            if ($date->gt($period->end)) {
                continue;
            }

            /**
             * Matched with `whereDate`, as `FinanceService` matches a day.
             *
             * A `date` attribute is written through the model's `Y-m-d H:i:s`
             * format, so on a driver that keeps the time the stored value has a
             * midnight on it and a bare `Y-m-d` never matches. The sheet would
             * then be written again beside itself on a second run, and every
             * driver on it would be paid for twice the days they worked.
             */
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

            // The helper's pay on their own line, through the one method that
            // keeps the day's helper total in step with its lines.
            app(FinanceService::class)->syncHelperLines($row, $helperId === null
                ? []
                : [['driver_id' => $helperId, 'salary_cents' => $helperPay]]);
        }
    }

    /**
     * Tell whoever ran this what to do next.
     *
     * A seeder that writes four employees and says nothing leaves somebody
     * guessing which period to open — and the period is the one thing they
     * cannot guess, because it comes from the firm's own cutoff.
     */
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
