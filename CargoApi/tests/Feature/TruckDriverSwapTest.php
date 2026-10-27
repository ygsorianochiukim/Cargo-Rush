<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Trucker\Models\Trucker;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->driver = function (string $name, string $employment = 'company'): array {
        static $n = 0;

        return $this->actingAs($this->admin)->postJson('/api/v1/drivers', [
            'name' => $name,
            'employment' => $employment,
            'licence_no' => 'SWAP-'.++$n,
            'licence_expiry' => '2029-08-31',
        ])->assertCreated()->json('data');
    };

    $this->truck = function (array $overrides = []): array {
        static $n = 0;

        return $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
            'plate' => 'SWP '.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-SWP-'.$n,
            'capacity_kg' => 15_000,
            ...$overrides,
        ])->assertCreated()->json('data');
    };

    $this->trip = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Iponan',
        'destination' => 'Bukidnon',
        'cargo' => 'Rice',
        'weight_kg' => 10_000,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'price_cents' => 1_000_000,
        ...$overrides,
    ]);
});

describe('a truck with a driver assigned', function (): void {
    it('puts that driver on a trip when the office names nobody', function (): void {
        $assigned = ($this->driver)('Marco Villanueva');
        $truck = ($this->truck)(['driver_id' => $assigned['id']]);

        $trip = ($this->trip)(['vehicle_id' => $truck['id']])->assertCreated()->json('data');

        expect($trip['driver_id'])->toBe($assigned['id'])
            ->and($trip['driver_name'])->toBe('Marco Villanueva')
            ->and($trip['vehicle_driver_id'])->toBe($assigned['id'])
            ->and($trip['vehicle_driver_name'])->toBe('Marco Villanueva')
            ->and($trip['driver_swapped'])->toBeFalse();
    });

    it('lets the office swap in another driver for that trip only', function (): void {
        $assigned = ($this->driver)('Marco Villanueva');
        $standIn = ($this->driver)('Jun Santos');
        $truck = ($this->truck)(['driver_id' => $assigned['id']]);

        $trip = ($this->trip)(['vehicle_id' => $truck['id'], 'driver_id' => $standIn['id']])
            ->assertCreated()->json('data');

        expect($trip['driver_id'])->toBe($standIn['id'])
            ->and($trip['driver_swapped'])->toBeTrue()
            ->and($trip['vehicle_driver_name'])->toBe('Marco Villanueva');

        $kept = $this->actingAs($this->admin)->getJson("/api/v1/vehicles/{$truck['id']}")->assertOk()->json('data');

        expect($kept['driver_id'])->toBe($assigned['id']);
    });

    it('fills the driver in when a truck is put on a trip that had none', function (): void {
        $assigned = ($this->driver)('Marco Villanueva');
        $truck = ($this->truck)(['driver_id' => $assigned['id']]);

        $trip = ($this->trip)(['status' => 'pending'])->assertCreated()->json('data');

        expect($trip['driver_id'])->toBeNull();

        $updated = $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip['id']}", ['vehicle_id' => $truck['id']])
            ->assertOk()->json('data');

        expect($updated['driver_id'])->toBe($assigned['id'])
            ->and($updated['driver_swapped'])->toBeFalse();
    });

    it('does not override a driver already on the trip when the truck changes', function (): void {
        $assigned = ($this->driver)('Marco Villanueva');
        $other = ($this->driver)('Jun Santos');
        $first = ($this->truck)(['driver_id' => $other['id']]);
        $second = ($this->truck)(['driver_id' => $assigned['id']]);

        $trip = ($this->trip)(['vehicle_id' => $first['id']])->assertCreated()->json('data');

        $moved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip['id']}", ['vehicle_id' => $second['id']])
            ->assertOk()->json('data');

        expect($moved['driver_id'])->toBe($other['id'])
            ->and($moved['driver_swapped'])->toBeTrue()
            ->and($moved['vehicle_driver_name'])->toBe('Marco Villanueva');
    });
});

describe('a sub-contracted truck', function (): void {
    it('may be added with or without a driver', function (): void {
        $owner = Trucker::factory()->approved()->create(['name' => 'Ortega Trucking', 'licence_no' => null]);
        $theirs = ($this->driver)('Rey Ortega', 'subcontractor');

        $bare = ($this->truck)(['arrangement' => 'subcontracted', 'owner_trucker_id' => $owner->id]);
        $crewed = ($this->truck)([
            'arrangement' => 'subcontracted', 'owner_trucker_id' => $owner->id, 'driver_id' => $theirs['id'],
        ]);

        expect($bare['driver_id'])->toBeNull()
            ->and($crewed['driver_id'])->toBe($theirs['id'])
            ->and($crewed['driver_name'])->toBe('Rey Ortega');
    });

    it('sends its own driver out on a trip, and leaves the trip unassigned when it has none', function (): void {
        $owner = Trucker::factory()->approved()->create(['name' => 'Ortega Trucking', 'licence_no' => null]);
        $theirs = ($this->driver)('Rey Ortega', 'subcontractor');

        $bare = ($this->truck)(['arrangement' => 'subcontracted', 'owner_trucker_id' => $owner->id]);
        $crewed = ($this->truck)([
            'arrangement' => 'subcontracted', 'owner_trucker_id' => $owner->id, 'driver_id' => $theirs['id'],
        ]);

        $withTheirs = ($this->trip)(['vehicle_id' => $crewed['id']])->assertCreated()->json('data');
        $withNobody = ($this->trip)(['vehicle_id' => $bare['id']])->assertCreated()->json('data');

        expect($withTheirs['driver_id'])->toBe($theirs['id'])
            ->and($withTheirs['driver_swapped'])->toBeFalse()
            ->and($withNobody['driver_id'])->toBeNull()
            ->and($withNobody['vehicle_driver_id'])->toBeNull()
            ->and($withNobody['driver_swapped'])->toBeFalse();
    });
});
