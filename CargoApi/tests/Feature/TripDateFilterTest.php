<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->travelTo('2026-11-13 00:00:00');

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->book = fn (string $when) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Manila',
        'destination' => 'Batangas',
        'cargo' => 'Cement, 200 bags',
        'weight_kg' => 3200,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'scheduled_at' => $when,
        'status' => StatusValue::Assigned->value,
    ])->assertCreated()->json('data.id');
});

it('reads from and to as Philippine calendar days', function (): void {
    $early = ($this->book)('2026-11-13T18:00:00Z');
    $late = ($this->book)('2026-11-14T15:30:00Z');
    $nextDay = ($this->book)('2026-11-14T17:00:00Z');
    $dayBefore = ($this->book)('2026-11-13T15:00:00Z');

    $ids = collect($this->actingAs($this->admin)
        ->getJson('/api/v1/trips?from=2026-11-14&to=2026-11-14&per_page=100')
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids)->toContain($early, $late)
        ->not->toContain($nextDay)
        ->not->toContain($dayBefore);
});
