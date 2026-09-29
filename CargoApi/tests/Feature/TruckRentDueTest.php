<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Expense;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * A flat-rented truck's rent is on the books for the whole month from its
 * first day, due on its last — so Payables shows it all month, and a month
 * still unpaid once it is over is overdue.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->travelTo(Carbon::parse('2026-09-10 09:00'));

    $this->rented = Vehicle::create([
        'plate' => 'TEST-002', 'model' => 'Ten-wheeler', 'registration_no' => 'LTO-2',
        'capacity_kg' => 10_000, 'wheels' => 10, 'status' => StatusValue::Available->value,
        'arrangement' => VehicleArrangement::Rented->value,
        'owner_name' => 'Delfin Hauling', 'rent_cents' => 5_000_000,
    ]);

    $this->rentLines = fn (): array => collect(
        $this->actingAs($this->admin)->getJson('/api/v1/finance/payables')->assertOk()->json('data.groups'),
    )->firstWhere('key', 'rented_trucks');
});

it('puts the whole month on the books from the first day, due on the last', function (): void {
    expect(app(TruckRentService::class)->chargeDue(now()))->toBe(1);

    $rent = Expense::query()->where('vehicle_id', $this->rented->getKey())->sole();

    expect($rent->amount_cents)->toBe(5_000_000)
        ->and($rent->date->toDateString())->toBe('2026-09-30')
        ->and($rent->status)->toBe(StatusValue::Pending);

    $group = ($this->rentLines)();

    expect($group['total_cents'])->toBe(5_000_000)
        ->and($group['overdue_count'])->toBe(0)
        ->and($group['lines'][0]['due_on'])->toBe('2026-09-30')
        ->and($group['lines'][0]['overdue'])->toBeFalse();
});

it('marks an unpaid month overdue once it is over, beside the new month', function (): void {
    app(TruckRentService::class)->chargeDue(now());

    $this->travelTo(Carbon::parse('2026-10-02 08:00'));
    app(TruckRentService::class)->chargeDue(now());

    $group = ($this->rentLines)();

    expect($group['total_cents'])->toBe(10_000_000)
        ->and($group['overdue_count'])->toBe(1)
        ->and(collect($group['lines'])->pluck('overdue', 'due_on')->all())
        ->toBe(['2026-09-30' => true, '2026-10-31' => false]);
});

it('catches up a month the job missed, but nothing before the truck existed', function (): void {
    // Nothing ran in September at all.
    $this->travelTo(Carbon::parse('2026-10-05'));

    expect(app(TruckRentService::class)->chargeDue(now()))->toBe(2);

    // Rented in September: August is not owed.
    expect(Expense::query()->where('vehicle_id', $this->rented->getKey())->pluck('date')
        ->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-09-30', '2026-10-31']);
});

it('is safe to run every day', function (): void {
    expect(app(TruckRentService::class)->chargeDue(now()))->toBe(1)
        ->and(app(TruckRentService::class)->chargeDue(now()->addDays(3)))->toBe(0);

    $this->artisan('cargo:truck-rent')->assertSuccessful();

    expect(Expense::query()->where('vehicle_id', $this->rented->getKey())->count())->toBe(1);
});

it('drops off Payables once the month is paid', function (): void {
    app(TruckRentService::class)->chargeDue(now());

    Expense::query()->where('vehicle_id', $this->rented->getKey())->update(['status' => StatusValue::Paid->value]);

    expect(($this->rentLines)()['total_cents'])->toBe(0);
});

it('charges the month straight away when a rented truck is added', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
        'plate' => 'NEW-RENT',
        'model' => 'Hino 500',
        'registration_no' => 'REG-9',
        'capacity_kg' => 10_000,
        'arrangement' => VehicleArrangement::Rented->value,
        'owner_name' => 'Owner',
        'rent_cents' => 4_500_000,
    ])->assertCreated()->json('data.id');

    expect(Expense::query()->where('vehicle_id', $id)->sole()->amount_cents)->toBe(4_500_000);
});
