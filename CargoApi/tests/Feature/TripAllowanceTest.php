<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Dry goods',
        'weight_kg' => 500,
        'distance_total_m' => 34_000,
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ...$overrides,
    ]);
});

it('books a trip without any allowance', function (): void {
    ($this->book)()
        ->assertCreated()
        ->assertJsonPath('data.driver_allowance_cents', null)
        ->assertJsonPath('data.helper_allowance_cents', null);
});

it('keeps the driver and helper allowances typed on the trip', function (): void {
    $trip = ($this->book)(['driver_allowance_cents' => 50_000, 'helper_allowance_cents' => 30_000])
        ->assertCreated()
        ->assertJsonPath('data.driver_allowance_cents', 50_000)
        ->assertJsonPath('data.helper_allowance_cents', 30_000)
        ->json('data');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$trip['id']}", ['driver_allowance_cents' => 60_000, 'helper_allowance_cents' => null])
        ->assertOk()
        ->assertJsonPath('data.driver_allowance_cents', 60_000)
        ->assertJsonPath('data.helper_allowance_cents', null);
});

it('refuses a negative allowance', function (): void {
    ($this->book)(['driver_allowance_cents' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('driver_allowance_cents');
});
