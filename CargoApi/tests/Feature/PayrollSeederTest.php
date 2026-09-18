<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Services\TripPayService;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\PayrollSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

/**
 * The demo data has to produce a payroll somebody can actually read.
 *
 * A seeder is the one piece of code whose whole job is to be looked at, so a
 * test that only proves it inserted rows proves the wrong thing. This runs the
 * payroll it sets up and checks the figures are the ones the seeder's own
 * docblock promises — ₱15,000 a month arriving as ₱7,500, and two drivers paid
 * what the truck sheet says rather than nothing.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(PayrollSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
});

it('builds a run every seeded person appears on', function (): void {
    $period = PayrollCalendar::default()->justClosed();

    $run = $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
        'period_start' => $period->start->toDateString(),
        'period_end' => $period->end->toDateString(),
        'pay_date' => $period->cutoff()->toDateString(),
    ])->assertCreated()->json('data');

    $lines = collect($run['lines'])->keyBy('name');

    expect($run['staff_count'])->toBe(4)
        // ₱15,000 a month, arriving as ₱7,500 on this payslip. The worked
        // example, asserted rather than described.
        ->and($lines['Elena Bautista']['basic_cents'])->toBe(750_000)
        ->and($lines['Elena Bautista']['pay_basis'])->toBe('monthly')
        ->and($lines['Rosa Lim']['basic_cents'])->toBe(1_100_000);

    /**
     * Both drivers paid the trip rate for the hauls they delivered.
     *
     * Five hauls each at ₱1,500, from different work: Marco drives four and
     * rides as helper on the last, Jun drives three and rides on two. Counted
     * by hand off the seeder's own day table, so this notices if somebody edits
     * it and forgets what the demo was supposed to show.
     *
     * The sheet days come to five for both as well, and the two counts agreeing
     * is the point — the truck sheet still records who was out, it simply no
     * longer decides the money.
     */
    expect($lines['Marco Villanueva']['pay_basis'])->toBe('per_trip')
        ->and($lines['Marco Villanueva']['basic_cents'])->toBe(750_000)
        ->and($lines['Marco Villanueva']['trips'])->toBe(5)
        ->and($lines['Marco Villanueva']['sheet_days'])->toBe(5)
        ->and($lines['Jun Santos']['basic_cents'])->toBe(750_000)
        ->and($lines['Jun Santos']['trips'])->toBe(5)
        ->and($lines['Jun Santos']['sheet_days'])->toBe(5);
});

it('can be run twice without doubling the roster', function (): void {
    $this->seed(PayrollSeeder::class);

    expect(Employee::query()->count())->toBe(4);
});

/**
 * Seeding into a company that already has records in it.
 *
 * The seeder is most useful on an install somebody is already using, which is
 * also where it could do the most damage. These are the two things it must not
 * touch: a job the office has already priced, and a real unit's truck sheet.
 */
describe('on a company already in use', function (): void {
    it('leaves a job the office has already priced alone', function (): void {
        // The office prices Driver as a monthly job before the demo runs.
        Position::query()->where('name', 'Driver')->update([
            'pay_basis' => 'monthly',
            'trainee_amount_cents' => 1_500_000,
            'probationary_amount_cents' => 1_650_000,
            'regular_amount_cents' => 1_800_000,
        ]);

        $this->seed(PayrollSeeder::class);

        $driver = Position::query()->where('name', 'Driver')->first();

        // Untouched. Repricing a live job would change what every future hire
        // is offered, and it would look like somebody's decision.
        expect($driver->regular_amount_cents)->toBe(1_800_000)
            ->and($driver->trainee_amount_cents)->toBe(1_500_000)
            ->and($driver->pay_basis->value)->toBe('monthly');
    });

    it('never writes onto a real truck’s sheet', function (): void {
        $real = Truck::create(['label' => 'Truck 1', 'plate' => 'NCR 4412', 'position' => 1]);

        $day = LedgerEntry::create([
            'truck_id' => $real->id,
            'date' => PayrollCalendar::default()->justClosed()->start->toDateString(),
            'trip_income_cents' => 9_999_00,
            'fuel_cents' => 1_111_00,
        ]);

        $this->seed(PayrollSeeder::class);

        // A day's takings and fuel are what Profitability reads. Overwriting
        // them with demo figures is the one genuinely destructive thing this
        // seeder could do, so it uses units of its own.
        expect($day->refresh()->trip_income_cents)->toBe(9_999_00)
            ->and($day->fuel_cents)->toBe(1_111_00)
            ->and(LedgerEntry::query()->where('truck_id', $real->id)->count())->toBe(1);
    });

    it('marks everything it writes so it can be taken back out', function (): void {
        expect(Employee::query()->where('employee_no', 'like', 'DEMO-%')->count())->toBe(4)
            ->and(Truck::query()->where('label', 'like', 'Demo Truck%')->count())->toBe(2)
            ->and(Driver::query()->where('licence_no', 'like', 'DEMO-LIC-%')->count())->toBe(2);
    });
});

/**
 * The delivered runs, and the thing that makes them worth seeding.
 *
 * A haul is now what per-trip pay is counted in, so every day of the sheet has
 * one behind it and every one names the crew the sheet names. A day with no
 * trip would pay its driver nothing while still showing them out on the road,
 * which is the most confusing shape this demo could take.
 */
describe('the demo trips', function (): void {
    it('puts a delivered run behind every day of the sheet', function (): void {
        $trips = Trip::query()->where('reference', 'like', 'DEMO-TR-%')->get();

        expect($trips)->toHaveCount(7)
            ->and($trips->pluck('status')->unique()->pluck('value')->all())->toBe(['delivered'])
            // Two drivers, not one: a demo where only one of them has hauls
            // against their name is a demo where the other is paid nothing.
            ->and($trips->pluck('driver_id')->unique())->toHaveCount(2)
            // Priced through the real rate card, not left at zero.
            ->and($trips->every(fn (Trip $trip): bool => $trip->price_cents > 0))->toBeTrue();
    });

    it('crews them exactly as the truck sheet crews those days', function (): void {
        $marco = Employee::query()->where('employee_no', 'DEMO-03')->firstOrFail();
        $jun = Employee::query()->where('employee_no', 'DEMO-04')->firstOrFail();
        $period = PayrollCalendar::default()->justClosed();

        $sheet = app(TripPayService::class);

        $his = $sheet->forEmployee($marco, $period->start, $period->end);
        $hers = $sheet->forEmployee($jun, $period->start, $period->end);

        // Five days on the sheet and five hauls delivered, for both of them.
        // The two counts agreeing is what says the day table and the trip list
        // have not drifted apart — and drifting is exactly what would show up
        // later as a driver paid for fewer hauls than days they were out.
        expect($his['days'])->toBe(5)
            ->and($his['trips'])->toBe(5)
            ->and($hers['days'])->toBe(5)
            ->and($hers['trips'])->toBe(5);

        // The sheet still records what the office spent on those days. It is
        // simply no longer what the payslip is worked out from.
        expect($his['earned_cents'])->toBe(515_000)
            ->and($hers['earned_cents'])->toBe(450_000);
    });
});
