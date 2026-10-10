<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => Role::Administrator->value,
    ]);

    $this->add = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/vehicles', [
            'plate' => 'NCR 4412',
            'model' => 'Isuzu Elf 4W',
            'registration_no' => 'LTO-2024-44120',
            'capacity_kg' => 4_000,
            ...$payload,
        ]);
});

describe('the driver', function (): void {
    it('is optional, because a truck arrives before anybody is put on it', function (): void {
        $vehicle = ($this->add)()->assertCreated()->json('data');

        expect($vehicle['driver_id'])->toBeNull();
    });

    it('is still accepted when somebody does hold the keys', function (): void {
        $driver = Driver::create([
            'name' => 'Marco Reyes',
            'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-01-01',
        ]);

        $vehicle = ($this->add)(['driver_id' => $driver->id])->assertCreated()->json('data');

        expect($vehicle['driver_id'])->toBe($driver->id);
    });
});

describe('the terms', function (): void {
    it('owes nobody anything on a truck the fleet owns', function (): void {
        ($this->add)(['arrangement' => 'owned'])->assertCreated();

        $vehicle = Vehicle::query()->firstOrFail();

        expect($vehicle->rent_cents)->toBeNull()
            ->and($vehicle->share_bp)->toBeNull()
            ->and($vehicle->owner_trucker_id)->toBeNull();
    });

    it('insists on the rent for a flat-rented truck', function (): void {
        ($this->add)(['arrangement' => 'rented'])
            ->assertStatus(422)
            ->assertJsonPath('errors.rent_cents.0', 'Enter the monthly rent for this truck.');
    });

    it('takes a flat-rented truck with its monthly figure', function (): void {
        ($this->add)([
            'arrangement' => 'rented',
            'rent_cents' => 5_000_000,
            'owner_name' => 'Delfin Uy',
        ])->assertCreated();

        expect(Vehicle::query()->firstOrFail()->rent_cents)->toBe(5_000_000);
    });

    it('insists on somebody to pay when a truck shares what it earns', function (): void {
        ($this->add)(['arrangement' => 'rented_share', 'share_bp' => 1500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_trucker_id');
    });

    it('refuses a share that is a typo rather than a negotiation', function (): void {
        ($this->add)(['arrangement' => 'rented_share', 'share_bp' => 9000])
            ->assertStatus(422);
    });
});
