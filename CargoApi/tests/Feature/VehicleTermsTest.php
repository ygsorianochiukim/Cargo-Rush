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

/**
 * Putting a truck on the fleet, and the terms that come with it.
 *
 * Four arrangements, and only one of them owes anybody anything. What made the
 * form confusing was that all four asked the same questions: adding a truck the
 * fleet owns outright wanted its monthly rent, the percentage the fleet keeps
 * and who to pay that to — three questions with no answer, on the commonest
 * case of the four.
 *
 * The screen now shows a term only when the arrangement has one. These are the
 * API's half of that: a driver is optional whatever the arrangement, and a term
 * that does not apply is refused a value rather than quietly stored against a
 * truck nobody owes anything for.
 */
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
        // Bought on a Friday, crewed on the Monday. The form says so in the
        // label now; this is the half that has to agree with it.
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

        // The form hides all three for an owned truck, and clears them on the
        // way out — hiding a control leaves its value behind, so a truck moved
        // from rented to owned would otherwise keep the rent somebody typed
        // before they changed their mind, and the rent command would go on
        // billing for a unit the fleet owns.
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
        // The other half of the split the form now draws: a share arrangement
        // asks who the share is credited to, and a rented one never does.
        ($this->add)(['arrangement' => 'rented_share', 'share_bp' => 1500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_trucker_id');
    });

    it('refuses a share that is a typo rather than a negotiation', function (): void {
        ($this->add)(['arrangement' => 'rented_share', 'share_bp' => 9000])
            ->assertStatus(422);
    });
});
