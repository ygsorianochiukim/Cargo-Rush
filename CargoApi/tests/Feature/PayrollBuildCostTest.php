<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->staff = function (int $count, int $from = 0): void {
        for ($i = $from; $i < $from + $count; $i++) {
            $employee = Employee::create([
                'employee_no' => 'EMP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'first_name' => 'Staff', 'last_name' => 'Number'.$i,
                'position' => 'Office Staff', 'contact' => '0917 555 0199',
                'hired_on' => '2026-01-05', 'status' => 'active',
            ]);

            payContract($employee, 'monthly', 3_000_000);
        }
    };

    $this->costOf = function (string $start, string $end): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
            'period_start' => $start, 'period_end' => $end, 'pay_date' => $end,
        ])->assertCreated();

        $count = collect(DB::getQueryLog())
            ->reject(static fn (array $query): bool => str_contains($query['query'], '_logs"'))
            ->count();
        DB::disableQueryLog();

        return $count;
    };
});

it('reads what it needs once, not once per employee', function (): void {
    ($this->staff)(5);
    $five = ($this->costOf)('2026-09-01', '2026-09-15');

    ($this->staff)(20, 5);
    $twentyFive = ($this->costOf)('2026-10-01', '2026-10-15');

    expect(($twentyFive - $five) / 20)->toBeLessThanOrEqual(2.0);
})->group('performance');

it('does not grow a read per head when the firm has a salary structure', function (): void {
    $components = collect([
        ['name' => 'Rice allowance', 'kind' => 'earning', 'amount_cents' => 200_000],
        ['name' => 'COLA', 'kind' => 'earning', 'amount_cents' => 150_000],
        ['name' => 'Canteen', 'kind' => 'deduction', 'amount_cents' => 30_000],
    ])->map(fn (array $spec) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll/components', [...$spec, 'schedule' => 'each_run'])
        ->assertCreated()->json('data.id'));

    $assign = function (int $skip, int $take) use ($components): void {
        $staff = Employee::query()->orderBy('employee_no')->skip($skip)->take($take)->get();

        foreach ($staff as $employee) {
            foreach ($components as $componentId) {
                $this->actingAs($this->admin)->postJson('/api/v1/payroll/assignments', [
                    'employee_id' => $employee->id,
                    'pay_component_id' => $componentId,
                    'effective_from' => '2026-01-01',
                ])->assertCreated();
            }
        }
    };

    ($this->staff)(5);
    $assign(0, 5);
    $five = ($this->costOf)('2026-09-01', '2026-09-15');

    ($this->staff)(15, 5);
    $assign(5, 15);
    $twentyFive = ($this->costOf)('2026-10-01', '2026-10-15');

    expect(($twentyFive - $five) / 15)->toBeLessThanOrEqual(5.0);
})->group('performance');
