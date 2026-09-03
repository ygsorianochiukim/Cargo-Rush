<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;

/**
 * What `php artisan db:seed` actually produces.
 *
 * The split this pins is the one that matters to a client: **configuration is
 * seeded, business data is not.** The navigation drives the whole shell, and
 * the accounts are how anyone opens it — an install missing either is not an
 * empty system, it is a broken one. Everything else is the business's own and
 * arrives through the UI.
 */
beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the navigation and the accounts, and no business data', function (): void {
    expect(User::count())->toBe(3)
        ->and(Vehicle::count())->toBe(0)
        ->and(Customer::count())->toBe(0)
        ->and(Trip::count())->toBe(0)
        ->and(Truck::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
});

it('gives one account per back-office role, plus a driver', function (): void {
    expect(User::pluck('role')->sort()->values()->all())
        ->toBe(['accountant', 'administrator', 'driver']);
});

it('renders a sidebar, because an empty nav table is an app with no chrome', function (): void {
    $admin = User::where('role', 'administrator')->firstOrFail();

    $items = $this->actingAs($admin)->getJson('/api/v1/navigation')->assertOk()->json('data');

    expect($items)->not->toBeEmpty();
    expect(collect($items)->pluck('key'))->toContain('dashboard', 'trips', 'monitoring');
});

/**
 * A driver login with no `drivers` row signs in fine and then has nothing to
 * show, because every driver endpoint is scoped to that record. Seeding the
 * account without it would ship a mobile app that opens on five 404s.
 */
it('gives the driver account a driver record, so the app is usable', function (): void {
    $driver = User::where('role', 'driver')->firstOrFail();

    expect($driver->driver)->not->toBeNull()
        ->and(Driver::count())->toBe(1);

    $this->actingAs($driver)->getJson('/api/v1/trips/pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('signs in with the configured seed password', function (): void {
    $this->postJson('/api/v1/login', [
        'email' => 'admin@cargorush.ph',
        'password' => env('SEED_PASSWORD', 'password'),
        'device_name' => 'test',
    ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'administrator');
});

it('lets the seeded administrator enter the first real record', function (): void {
    $admin = User::where('role', 'administrator')->firstOrFail();

    // The whole point of keeping the accounts: sign in, then start entering
    // the actual fleet without a developer involved.
    $this->actingAs($admin)->postJson('/api/v1/vehicles', [
        'plate' => 'REAL 0001',
        'model' => 'Isuzu Forward',
        'registration_no' => 'LTO-2026-00001',
        'capacity_kg' => 8000,
    ])->assertCreated();

    expect(Vehicle::count())->toBe(1);
});
