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

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(PayrollSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
});

it('builds an office run and a crew run every seeded person appears on', function (): void {
    $period = PayrollCalendar::default()->justClosed();

    $open = fn (string $scope): array => $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
        'scope' => $scope,
        'period_start' => $period->start->toDateString(),
        'period_end' => $period->end->toDateString(),
        'pay_date' => $period->cutoff()->toDateString(),
    ])->assertCreated()->json('data');

    $office = $open('office');
    $crew = $open('crew');

    $staff = collect($office['lines'])->keyBy('name');
    $lines = collect($crew['lines'])->keyBy('name');

    expect($office['staff_count'])->toBe(2)
        ->and($office['reference'])->toStartWith('PR-')
        ->and($staff['Elena Bautista']['basic_cents'])->toBe(750_000)
        ->and($staff['Elena Bautista']['pay_basis'])->toBe('monthly')
        ->and($staff['Rosa Lim']['basic_cents'])->toBe(1_100_000);

    expect($crew['staff_count'])->toBe(2)
        ->and($crew['reference'])->toStartWith('CP-')
        ->and($lines['Marco Villanueva']['pay_basis'])->toBe('per_trip')
        ->and($lines['Marco Villanueva']['basic_cents'])->toBe(750_000)
        ->and($lines['Marco Villanueva']['trips'])->toBe(5)
        ->and($lines['Marco Villanueva']['sheet_days'])->toBe(5)
        ->and($lines['Marco Villanueva']['trip_details'])->toHaveCount(5)
        ->and($lines['Jun Santos']['basic_cents'])->toBe(750_000)
        ->and($lines['Jun Santos']['trips'])->toBe(5)
        ->and($lines['Jun Santos']['sheet_days'])->toBe(5);
});

it('can be run twice without doubling the roster', function (): void {
    $this->seed(PayrollSeeder::class);

    expect(Employee::query()->count())->toBe(4);
});

describe('on a company already in use', function (): void {
    it('leaves a job the office has already priced alone', function (): void {
        Position::query()->where('name', 'Driver')->update([
            'pay_basis' => 'monthly',
            'trainee_amount_cents' => 1_500_000,
            'probationary_amount_cents' => 1_650_000,
            'regular_amount_cents' => 1_800_000,
        ]);

        $this->seed(PayrollSeeder::class);

        $driver = Position::query()->where('name', 'Driver')->first();

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

describe('the demo trips', function (): void {
    it('puts a delivered run behind every day of the sheet', function (): void {
        $trips = Trip::query()->where('reference', 'like', 'DEMO-TR-%')->get();

        expect($trips)->toHaveCount(7)
            ->and($trips->pluck('status')->unique()->pluck('value')->all())->toBe(['delivered'])
            ->and($trips->pluck('driver_id')->unique())->toHaveCount(2)
            ->and($trips->every(fn (Trip $trip): bool => $trip->price_cents > 0))->toBeTrue();
    });

    it('crews them exactly as the truck sheet crews those days', function (): void {
        $marco = Employee::query()->where('employee_no', 'DEMO-03')->firstOrFail();
        $jun = Employee::query()->where('employee_no', 'DEMO-04')->firstOrFail();
        $period = PayrollCalendar::default()->justClosed();

        $sheet = app(TripPayService::class);

        $his = $sheet->forEmployee($marco, $period->start, $period->end);
        $hers = $sheet->forEmployee($jun, $period->start, $period->end);

        expect($his['days'])->toBe(5)
            ->and($his['trips'])->toBe(5)
            ->and($hers['days'])->toBe(5)
            ->and($hers['trips'])->toBe(5);

        expect($his['earned_cents'])->toBe(515_000)
            ->and($hers['earned_cents'])->toBe(450_000);
    });
});
