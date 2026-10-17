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
        $seeder = app(PayrollSeeder::class);
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }

    private function remove(): int
    {
        if (! $this->confirmRemoval()) {
            $this->line('Left alone.');

            return self::SUCCESS;
        }

        $removed = DB::transaction(function (): array {
            $trips = Trip::query()
                ->where(fn ($q) => $q->where('reference', 'like', 'DEMO-TR-%')->orWhere('legacy_reference', 'like', 'DEMO-TR-%'))
                ->delete();

            $trucks = Truck::query()->whereIn('label', ['Demo Truck A', 'Demo Truck B'])->pluck('id');

            $rows = LedgerEntry::query()->whereIn('truck_id', $trucks)->delete();
            Truck::query()->whereIn('id', $trucks)->delete();

            $staff = Employee::query()->where('employee_no', 'like', 'DEMO-%')->get();

            $people = 0;

            foreach ($staff as $employee) {
                $employee->delete();
                $people++;
            }

            $drivers = Driver::query()->where('licence_no', 'like', 'DEMO-LIC-%')->delete();

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
