<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * A partner managing their own trucks from the app.
 *
 * Sign-up takes one truck; everything after that is here. A partner may run
 * several, and a run goes under the first one marked available — so adding a
 * truck and taking one off the road are the two things that matter, and a
 * partner must never be able to touch somebody else's.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->partner = function (string $status = StatusValue::Active->value): array {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);

        $trucker = Trucker::factory()->create(['user_id' => $user->getKey(), 'status' => $status]);
        $truck = TruckerVehicle::factory()->create([
            'trucker_id' => $trucker->getKey(),
            'status' => StatusValue::Available->value,
        ]);

        return [$user, $trucker, $truck];
    };

    [$this->user, $this->trucker, $this->first] = ($this->partner)();
});

it('adds a second truck and lists both', function (): void {
    $this->actingAs($this->user)
        ->postJson('/api/v1/partner/vehicles', [
            'plate' => 'KAB-2231',
            'model' => 'Hino 500',
            'capacity_kg' => 8000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.plate', 'KAB-2231')
        ->assertJsonPath('data.status', StatusValue::Available->value);

    $plates = $this->actingAs($this->user)
        ->getJson('/api/v1/partner/vehicles')
        ->assertOk()
        ->json('data.*.plate');

    expect($plates)->toHaveCount(2)->toContain($this->first->plate, 'KAB-2231');
});

it('makes a partner still waiting on approval wait to add a truck', function (): void {
    [$user] = ($this->partner)(StatusValue::Pending->value);

    $this->actingAs($user)
        ->postJson('/api/v1/partner/vehicles', [
            'plate' => 'KAB-9001',
            'model' => 'Isuzu Elf',
            'capacity_kg' => 4000,
        ])
        ->assertForbidden();
});

it('says so when a plate is already registered, rather than failing', function (): void {
    $this->actingAs($this->user)
        ->postJson('/api/v1/partner/vehicles', [
            'plate' => $this->first->plate,
            'model' => 'Hino 500',
            'capacity_kg' => 8000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plate');
});

it('takes a truck off the road, and the next available one takes the work', function (): void {
    $second = TruckerVehicle::factory()->create([
        'trucker_id' => $this->trucker->getKey(),
        'status' => StatusValue::Available->value,
    ]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/partner/vehicles/{$this->first->id}", ['status' => 'maintenance'])
        ->assertOk()
        ->assertJsonPath('data.status', StatusValue::Maintenance->value);

    expect($this->trucker->refresh()->load('vehicles')->activeVehicle()?->id)->toBe($second->id);
});

it('refuses a status other than available or maintenance', function (): void {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/partner/vehicles/{$this->first->id}", ['status' => 'in_transit'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('refuses a new truck without what it can carry', function (): void {
    $this->actingAs($this->user)
        ->postJson('/api/v1/partner/vehicles', ['plate' => 'KAB-1', 'model' => 'Hino'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('capacity_kg');
});

it('will not let one partner change another partner\'s truck', function (): void {
    [, , $theirs] = ($this->partner)();

    $this->actingAs($this->user)
        ->patchJson("/api/v1/partner/vehicles/{$theirs->id}", ['status' => 'maintenance'])
        ->assertNotFound();

    expect($theirs->refresh()->status)->toBe(StatusValue::Available);
});

it('shows the office every truck on the account', function (): void {
    TruckerVehicle::factory()->count(2)->create(['trucker_id' => $this->trucker->getKey()]);

    $admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->actingAs($admin)
        ->getJson("/api/v1/truckers/{$this->trucker->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data.vehicles');
});
