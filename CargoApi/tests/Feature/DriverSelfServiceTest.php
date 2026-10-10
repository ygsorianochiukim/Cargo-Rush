<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Incident\Models\Incident;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\Demo\OperationsSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(OperationsSeeder::class);

    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::query()->firstWhere('name', 'Marco Reyes');
    $this->otherDriver = Driver::query()->firstWhere('name', 'Liza Tan');
    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
});

describe('a driver saying whether they are free', function (): void {
    it('can flip their own switch', function (): void {
        $this->driver->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($this->driverUser)
            ->postJson('/api/v1/drivers/me/availability', ['available' => true])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Available->value);

        expect($this->driver->refresh()->status)->toBe(StatusValue::Available);
    });

    it('is not asked for a driver id at all', function (): void {
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/drivers/{$this->driver->id}/availability", ['available' => true])
            ->assertForbidden();

        $this->actingAs($this->driverUser)
            ->postJson('/api/v1/drivers/me/availability', ['available' => false])
            ->assertOk()
            ->assertJsonPath('data.id', $this->driver->id);
    });

    it('cannot take anybody else off the board', function (): void {
        $this->otherDriver->update(['status' => StatusValue::Available->value]);

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/drivers/{$this->otherDriver->id}/availability", ['available' => false])
            ->assertForbidden();

        expect($this->otherDriver->refresh()->status)->toBe(StatusValue::Available);
    });

    it('answers a back-office account with a 404 rather than a wrong row', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/drivers/me/availability', ['available' => true])
            ->assertNotFound();
    });

    it('still lets the office set somebody availability', function (): void {
        $this->otherDriver->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/drivers/{$this->otherDriver->id}/availability", ['available' => true])
            ->assertOk();

        expect($this->otherDriver->refresh()->status)->toBe(StatusValue::Available);
    });
});

describe('a driver reporting an incident', function (): void {
    it('files it against themselves, their unit and their run', function (): void {
        $trip = Trip::query()->firstWhere('reference', 'CR-24817');

        $response = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
            'notes' => 'Nearside rear. Waiting on a replacement.',
        ])->assertCreated();

        expect($response->json('data.reference'))->toStartWith('INC-')
            ->and($response->json('data.kind'))->toBe('Tyre blowout')
            ->and($response->json('data.driver_id'))->toBe($this->driver->id)
            ->and($response->json('data.vehicle_plate'))->toBe($trip->vehicle?->plate)
            ->and($response->json('data.trip_reference'))->toBe('CR-24817')
            ->and($response->json('data.status'))->toBe(StatusValue::Pending->value);
    });

    it('stamps the time it happened when nobody says otherwise', function (): void {
        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Traffic hold',
            'place' => 'Kennon Road km 21',
        ])->assertCreated()->json('data.reference');

        $incident = Incident::query()->firstWhere('reference', $reference);

        expect($incident->occurred_at->diffInMinutes(now()))->toBeLessThan(2);
    });

    it('accepts a time for one written up afterwards', function (): void {
        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Cargo damage',
            'place' => 'Dumaguete depot',
            'occurred_at' => now()->subHours(3)->toIso8601String(),
        ])->assertCreated()->json('data.reference');

        expect(Incident::query()->firstWhere('reference', $reference)->occurred_at->diffInHours(now()))
            ->toBeGreaterThanOrEqual(2);
    });

    it('refuses one that has not happened yet', function (): void {
        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Overheating',
            'place' => 'Davao bypass',
            'occurred_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    });

    it('needs to say what happened and where', function (): void {
        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind', 'place']);
    });

    it('cannot pin it on another driver or another run', function (): void {
        $theirTrip = Trip::query()->firstWhere('reference', 'CR-24818');

        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
            'driver_id' => $this->otherDriver->id,
            'trip_id' => $theirTrip->id,
            'status' => StatusValue::Delivered->value,
        ])->assertCreated()->json('data.reference');

        $incident = Incident::query()->firstWhere('reference', $reference);

        expect($incident->driver_id)->toBe($this->driver->id)
            ->and($incident->trip_id)->not->toBe($theirTrip->id)
            ->and($incident->status)->toBe(StatusValue::Pending);
    });

    it('tells the office it happened', function (): void {
        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
        ])->assertCreated();

        $told = NotificationItem::query()->where('title', 'like', 'Incident INC-%')->first();

        expect($told)->not->toBeNull()
            ->and($told->detail)->toContain('Marco Reyes')
            ->and($told->tone->value)->toBe('danger');
    });

    it('reads back only their own reports', function (): void {
        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Windscreen chip',
            'place' => 'SLEX km 58',
        ])->assertCreated()->json('data.reference');

        $mine = $this->actingAs($this->driverUser)->getJson('/api/v1/incidents/mine')
            ->assertOk()
            ->json('data');

        $names = array_unique(array_column($mine, 'driver_name'));
        $references = array_column($mine, 'reference');

        expect($names)->toBe(['Marco Reyes'])
            ->and($references)->toContain($reference)
            ->and($references)->not->toContain('INC-0229');
    });

    it('is refused the office log and the office write-up', function (): void {
        $this->actingAs($this->driverUser)->getJson('/api/v1/incidents')->assertForbidden();

        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
            'occurred_at' => now()->toIso8601String(),
            'driver_id' => $this->otherDriver->id,
        ])->assertForbidden();
    });

    it('answers a back-office account with a 404 rather than filing against nobody', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
        ])->assertNotFound();
    });
});
