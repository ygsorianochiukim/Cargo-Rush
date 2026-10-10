<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

    $this->here = ['lat' => 8.4856, 'lng' => 124.5808];

    $this->partnerAt = function (string $name, float $lat, float $lng, int $capacityKg = 15_000): Trucker {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);

        $trucker = Trucker::factory()->approved()->at($lat, $lng)->create([
            'user_id' => $user->getKey(),
            'name' => $name,
        ]);

        TruckerVehicle::factory()->carrying($capacityKg)->create([
            'trucker_id' => $trucker->getKey(),
        ]);

        return $trucker->refresh()->load('vehicles');
    };

    $this->haulers = fn (array $point = []) => $this->actingAs($this->customer)
        ->getJson('/api/v1/portal/haulers?'.http_build_query($point ?: $this->here));
});

describe('the hauler list', function (): void {
    it('always leads with the fleet', function (): void {
        $body = ($this->haulers)()->assertOk()->json('data');

        expect($body[0]['kind'])->toBe('company')
            ->and($body[0]['name'])->toBe($this->company->name);
    });

    it('counts a unit out on a run as one the fleet has', function (): void {
        Vehicle::query()->update(['status' => StatusValue::Active->value]);

        $fleet = ($this->haulers)()->assertOk()->json('data.0');

        expect($fleet['vehicles_ready'])->toBeGreaterThan(0)
            ->and($fleet['capacity_kg'])->toBeGreaterThan(0);
    });

    it('offers the fleet even when nobody is anywhere near', function (): void {
        ($this->partnerAt)('Faraway Fred', 14.5995, 120.9842);

        $body = ($this->haulers)()->assertOk()->json('data');

        expect($body)->toHaveCount(1)
            ->and($body[0]['kind'])->toBe('company');
    });

    it('shows a trucker who is genuinely close', function (): void {
        ($this->partnerAt)('Boyet', 8.5100, 124.5700);

        $body = ($this->haulers)()->assertOk()->json('data');

        expect($body)->toHaveCount(2)
            ->and($body[1]['kind'])->toBe('trucker')
            ->and($body[1]['name'])->toBe('Boyet')
            ->and($body[1]['distance_km'])->toBeLessThan(10);
    });

    it('puts the nearest trucker first', function (): void {
        ($this->partnerAt)('Further', 8.7000, 124.7500);
        ($this->partnerAt)('Nearer', 8.5100, 124.5700);

        $body = ($this->haulers)()->assertOk()->json('data');

        expect($body[0]['kind'])->toBe('company')
            ->and($body[1]['name'])->toBe('Nearer')
            ->and($body[2]['name'])->toBe('Further');
    });

    it('hides a trucker whose position is stale', function (): void {
        $stale = ($this->partnerAt)('Yesterday Man', 8.5100, 124.5700);
        $stale->update(['located_at' => now()->subDays(2)]);

        expect(($this->haulers)()->assertOk()->json('data'))->toHaveCount(1);
    });

    it('hides a trucker who has gone offline or been stood down', function (): void {
        $offline = ($this->partnerAt)('Asleep', 8.5100, 124.5700);
        $offline->update(['is_online' => false]);

        $held = ($this->partnerAt)('On hold', 8.5100, 124.5700);
        $held->update(['status' => StatusValue::Inactive->value]);

        expect(($this->haulers)()->assertOk()->json('data'))->toHaveCount(1);
    });

    it('tells the customer nothing private about a partner', function (): void {
        ($this->partnerAt)('Boyet', 8.5100, 124.5700);

        $row = ($this->haulers)()->assertOk()->json('data.1');

        expect($row)->not->toHaveKey('phone')
            ->and($row)->not->toHaveKey('licence_no')
            ->and($row)->toHaveKeys(['name', 'capacity_kg', 'distance_km', 'trips_completed']);
    });
});

describe('picking one', function (): void {
    beforeEach(function (): void {
        $this->trucker = ($this->partnerAt)('Boyet', 8.5100, 124.5700);
        $this->truckerUser = User::find($this->trucker->user_id);

        $this->ask = fn (array $overrides = []) => $this->actingAs($this->customer)
            ->postJson('/api/v1/portal/requests', [
                'origin' => 'Iponan',
                'destination' => 'Bukidnon',
                'cargo' => 'Rice, 200 sacks',
                'weight_kg' => 10_000,
                'preferred_at' => now()->addDay()->toIso8601String(),
                ...$overrides,
            ]);
    });

    it('holds the request for the trucker the customer named', function (): void {
        ($this->ask)(['trucker_id' => $this->trucker->getKey()])->assertCreated();

        $trip = Trip::query()->latest('created_at')->firstOrFail();

        expect($trip->trucker_id)->toBe($this->trucker->getKey())
            ->and($trip->status)->toBe(StatusValue::Pending);
    });

    it('tells the trucker somebody asked for them', function (): void {
        ($this->ask)(['trucker_id' => $this->trucker->getKey()])->assertCreated();

        expect(NotificationItem::query()
            ->where('user_id', $this->truckerUser->getKey())
            ->where('title', 'A customer asked for you')
            ->exists())->toBeTrue();
    });

    it('shows the offer to that trucker and to nobody else', function (): void {
        $other = ($this->partnerAt)('Somebody Else', 8.5100, 124.5700);
        $otherUser = User::find($other->user_id);

        ($this->ask)(['trucker_id' => $this->trucker->getKey()])->assertCreated();

        $this->actingAs($this->truckerUser)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($otherUser)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('will not let another trucker take an offer that is not theirs', function (): void {
        $other = ($this->partnerAt)('Somebody Else', 8.5100, 124.5700);

        ($this->ask)(['trucker_id' => $this->trucker->getKey()])->assertCreated();
        $trip = Trip::query()->latest('created_at')->firstOrFail();

        $this->actingAs(User::find($other->user_id))
            ->postJson("/api/v1/partner/jobs/{$trip->id}/accept")
            ->assertStatus(409);
    });

    it('bills the customer through the trucker once they accept', function (): void {
        ($this->ask)(['trucker_id' => $this->trucker->getKey()])->assertCreated();
        $trip = Trip::query()->latest('created_at')->firstOrFail();

        $this->actingAs($this->truckerUser)
            ->postJson("/api/v1/partner/jobs/{$trip->id}/accept")
            ->assertCreated();

        expect($trip->refresh()->booking_source)->toBe(BookingSource::Direct)
            ->and($trip->status)->toBe(StatusValue::Assigned);
    });

    it('leaves the request with the office when nobody is named', function (): void {
        ($this->ask)()->assertCreated();

        $trip = Trip::query()->latest('created_at')->firstOrFail();

        expect($trip->trucker_id)->toBeNull();

        $this->actingAs($this->truckerUser)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('refuses a trucker who is not available', function (): void {
        $this->trucker->update(['is_online' => false]);

        ($this->ask)(['trucker_id' => $this->trucker->getKey()])->assertStatus(422);
    });

    it('refuses a trucker whose truck is too small', function (): void {
        $small = ($this->partnerAt)('Small Truck', 8.5100, 124.5700, capacityKg: 2_000);

        ($this->ask)(['trucker_id' => $small->getKey(), 'weight_kg' => 10_000])
            ->assertStatus(422);
    });

    it('refuses a trucker at another fleet', function (): void {
        $rival = $this->makeCompany('Bay Coast Logistics');
        $theirs = $this->asCompany($rival, fn () => Trucker::factory()->approved()->create());

        ($this->ask)(['trucker_id' => $theirs->getKey()])->assertNotFound();
    });
});
