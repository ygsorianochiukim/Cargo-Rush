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

/**
 * Building a run must not cost a round trip per person, per thing it needs.
 *
 * It used to. The salary components and the daily truck sheet were each read
 * **once per employee**, so a run cost about four queries a head — some four
 * hundred for a ninety-strong roster, paid again every time somebody corrected
 * a figure and rebuilt the draft. Both are now read once for the whole run.
 *
 * A query **count** rather than a timing, because time is a property of the
 * machine and the count is a property of the code. The bounds are deliberately
 * loose: this guards against a per-employee *lookup* coming back, not against
 * the one insert each payslip genuinely needs, and it should not fail because
 * somebody added a column.
 */
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

    /**
     * Queries used by one build.
     *
     * The log is flushed and enabled *immediately* before the request, so the
     * fixture's own inserts are not counted — measuring those was the first
     * mistake this file made, and it made the improvement look smaller than it
     * was.
     */
    $this->costOf = function (string $start, string $end): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
            'period_start' => $start, 'period_end' => $end, 'pay_date' => $end,
        ])->assertCreated();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
});

it('reads what it needs once, not once per employee', function (): void {
    ($this->staff)(5);
    $five = ($this->costOf)('2026-09-01', '2026-09-15');

    ($this->staff)(20, 5);
    $twentyFive = ($this->costOf)('2026-10-01', '2026-10-15');

    // One insert for the payslip itself is the floor, and is not a round trip
    // anything can avoid. Much above it means something is being looked up per
    // person again.
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

    // One payslip insert plus one per itemised component — four writes a head,
    // and crucially no *reads* that scale with the roster.
    expect(($twentyFive - $five) / 15)->toBeLessThanOrEqual(5.0);
})->group('performance');
