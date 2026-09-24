<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Console;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Hr\Models\Employee;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\PayrollSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Put a worked payroll into a company you already have.
 *
 *     php artisan cargo:demo-payroll
 *     php artisan cargo:demo-payroll --company=southern-freight
 *     php artisan cargo:demo-payroll --remove
 *
 * The seeder behind this could already be run with `db:seed`, and picked its
 * company from a `DEMO_COMPANY` environment variable. That is a fine mechanism
 * and a poor question to ask somebody: on an install with more than one firm it
 * is invisible, easy to forget, and getting it wrong fills the wrong company's
 * roster. **Which company** is the first thing this asks, out loud, the same way
 * `cargo:user` does — and for the same reason, which is that the answer is not
 * recoverable by guessing afterwards.
 *
 * ## Everything it writes is marked
 *
 * Staff numbered `DEMO-01`…`DEMO-04`, licences `DEMO-LIC-*`, units called
 * *Demo Truck A* and *B*. Not decoration: it is what makes `--remove` able to
 * take the demo back out of a company that has real records in it, and what
 * lets somebody looking at the roster tell at a glance which four people are
 * not real.
 *
 * ## What it will not touch
 *
 * A position the office has already priced, and any truck sheet row belonging
 * to a real unit. Both are explained where they happen in `PayrollSeeder`; the
 * short version is that a demo is not worth overwriting somebody's rates or a
 * day's takings for.
 */
class DemoPayrollCommand extends Command
{
    protected $signature = 'cargo:demo-payroll
        {--company= : Company name, code or id}
        {--remove : Take the demo payroll back out again}';

    protected $description = 'Seed a worked payroll — two salaried staff, two drivers paid per trip';

    public function __construct(private readonly Tenant $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $company = $this->company();

        if ($company === null) {
            return self::FAILURE;
        }

        return $this->tenant->use($company, function () use ($company): int {
            $this->line("Company: <options=bold>{$company->name}</> ({$company->code})");

            return $this->option('remove')
                ? $this->remove()
                : $this->seed();
        });
    }

    private function seed(): int
    {
        // Resolved rather than constructed: the seeder quotes its demo trips
        // through `PricingService`, so it has a dependency to be given.
        $seeder = app(PayrollSeeder::class);
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }

    /**
     * Take it back out.
     *
     * Only the rows this command wrote, found by the markers it wrote them
     * with. The positions are **kept**: they may have been priced or hired into
     * since, and a job title is the kind of thing an office adopts without
     * noticing where it came from. Removing four obviously-fake people and two
     * obviously-fake trucks is safe; removing "Admin" is not.
     */
    private function remove(): int
    {
        if (! $this->confirmRemoval()) {
            $this->line('Left alone.');

            return self::SUCCESS;
        }

        $removed = DB::transaction(function (): array {
            /**
             * The runs first, because everything else is something they point
             * at.
             *
             * These were missing, and their absence was the whole problem with
             * this flag: the seeder writes trips, a vehicle and a customer, and
             * `--remove` took out only the trucks and the sheet. What was left
             * behind was four trips marked *delivered* and never billed,
             * against a vehicle and a customer nobody could account for —
             * demo rows sitting in the finance screens of a real company, which
             * is exactly what somebody runs this flag to be rid of.
             *
             * A removal has to undo its own seeder. Anything less is a seeder
             * with no way back.
             */
            $trips = Trip::query()->where('reference', 'like', 'DEMO-TR-%')->delete();

            $trucks = Truck::query()->whereIn('label', ['Demo Truck A', 'Demo Truck B'])->pluck('id');

            $rows = LedgerEntry::query()->whereIn('truck_id', $trucks)->delete();
            Truck::query()->whereIn('id', $trucks)->delete();

            $staff = Employee::query()->where('employee_no', 'like', 'DEMO-%')->get();

            // The employee first: a pay run line points at it with
            // `restrictOnDelete`, so a demo that has already been paid refuses
            // here rather than half-deleting. That is the right answer — the
            // payslip is a record, and this says so instead of forcing it.
            $people = 0;

            foreach ($staff as $employee) {
                $employee->delete();
                $people++;
            }

            $drivers = Driver::query()->where('licence_no', 'like', 'DEMO-LIC-%')->delete();

            // The unit and the shipper the demo invented, matched on the marks
            // the seeder stamped them with rather than on their names: a plate
            // and a registration nobody would type by accident.
            $vehicles = Vehicle::query()->where('registration_no', 'DEMO-LTO-0001')->delete();
            $customers = Customer::query()->where('name', 'Demo Trading Co.')->delete();

            return [
                'sheet' => $rows,
                'staff' => $people,
                'drivers' => $drivers,
                'trips' => $trips,
                'vehicles' => $vehicles,
                'customers' => $customers,
            ];
        });

        $this->info(sprintf(
            'Removed %d staff, %d driver records, %d sheet rows, %d trips, %d vehicles and '
            .'%d customers. The Admin, Treasury Officer and Driver positions were kept.',
            $removed['staff'],
            $removed['drivers'],
            $removed['sheet'],
            $removed['trips'],
            $removed['vehicles'],
            $removed['customers'],
        ));

        return self::SUCCESS;
    }

    private function confirmRemoval(): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: 'Remove the demo staff, driver records and their truck sheet rows?',
            default: true,
        );
    }

    /**
     * Which company, asked before anything else happens.
     *
     * A single-company install answers itself — there is nothing to choose and
     * asking would be noise. Anything else asks, because filling the wrong
     * firm's roster is not something you notice from the output.
     */
    private function company(): ?Company
    {
        $named = trim((string) $this->option('company'));

        if ($named !== '') {
            $company = Company::query()
                ->where('id', $named)
                ->orWhere('code', $named)
                ->orWhere('name', $named)
                ->first();

            if ($company === null) {
                $this->error("No company matching \"{$named}\".");

                return null;
            }

            return $company;
        }

        $companies = Company::query()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            $this->error('No companies on this install. Register one first.');

            return null;
        }

        if ($companies->count() === 1) {
            return $companies->first();
        }

        $id = select(
            label: 'Which company?',
            options: $companies->pluck('name', 'id')->all(),
        );

        return $companies->firstWhere('id', $id);
    }
}
