<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
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
    $this->customer = Customer::query()->firstOrFail();

    $this->partner = function (string $name, int $capacityKg = 15_000): array {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);

        $trucker = Trucker::factory()->approved()->create([
            'user_id' => $user->getKey(),
            'name' => $name,
        ]);

        TruckerVehicle::factory()->carrying($capacityKg)->create([
            'trucker_id' => $trucker->getKey(),
        ]);

        return [$user, $trucker->refresh()->load('vehicles')];
    };

    $this->load = fn (array $overrides = []) => Trip::create([
        'customer_id' => $this->customer->getKey(),
        'origin' => 'Iponan',
        'destination' => 'Bukidnon',
        'cargo' => 'Rice, 200 sacks',
        'weight_kg' => 10_000,
        'status' => StatusValue::Pending->value,
        'scheduled_at' => now()->addDay(),
        'price_cents' => 1_000_000,
        'currency' => 'PHP',
        ...$overrides,
    ]);

    $this->offer = fn (Trucker $trucker, array $overrides = []) => ($this->load)([
        'trucker_id' => $trucker->getKey(),
        ...$overrides,
    ]);
});

describe('what a partner is shown', function (): void {
    it('shows a job offered to them, with their take worked out on it', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        ($this->offer)($trucker);

        $this->actingAs($user)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price_cents', 1_000_000)
            ->assertJsonPath('data.0.commission_bp', 1200)
            ->assertJsonPath('data.0.your_take_cents', 880_000);
    });

    it('quotes their take on the net when prices include VAT', function (): void {
        $this->company->update(['prices_include_vat' => true]);

        [$user, $trucker] = ($this->partner)('Boyet');
        ($this->offer)($trucker);

        $net = 892_857;

        $this->actingAs($user)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonPath('data.0.your_take_cents', $net - intdiv($net * 1200, 10_000));
    });

    it('never shows work nobody has offered them', function (): void {
        [$user] = ($this->partner)('Boyet');
        ($this->load)();

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('hides a job offered to somebody else', function (): void {
        [$user] = ($this->partner)('Boyet');
        [, $other] = ($this->partner)('Liza');

        ($this->offer)($other);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('hides a job one of the fleet own drivers has been put on', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $driver = Driver::query()->firstOrFail();

        ($this->offer)($trucker, ['driver_id' => $driver->getKey()]);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('hides a job that has already been confirmed', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        ($this->offer)($trucker, ['status' => StatusValue::Assigned->value]);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('hides a load their truck cannot carry', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet', capacityKg: 5_000);
        ($this->offer)($trucker, ['weight_kg' => 10_000]);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('matches the kind of truck a load asks for', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');

        $freezer = TruckCategory::create([
            'key' => 'freezer',
            'name' => 'Freezer / Reefer',
        ]);
        $flatbed = TruckCategory::create([
            'key' => 'flatbed',
            'name' => 'Flatbed',
        ]);

        $trucker->vehicles()->first()->update(['truck_category_id' => $flatbed->getKey()]);

        ($this->offer)($trucker, ['truck_category_id' => $freezer->getKey(), 'cargo' => 'Frozen tuna']);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows a load that names no kind of truck at all', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');

        $flatbed = TruckCategory::create([
            'key' => 'flatbed',
            'name' => 'Flatbed',
        ]);
        $trucker->vehicles()->first()->update(['truck_category_id' => $flatbed->getKey()]);

        ($this->offer)($trucker, ['truck_category_id' => null]);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(1, 'data');
    });

    it('does not put work the desk assigned them back on the board', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->load)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk();

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($user)->getJson('/api/v1/partner/trips')->assertOk()->assertJsonCount(1, 'data');
    });

    it('puts the nearest load first', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trucker->update(['latitude' => 8.4856, 'longitude' => 124.5808, 'located_at' => now()]);

        $far = ($this->offer)($trucker, ['origin' => 'Davao', 'origin_lat' => 7.0731, 'origin_lng' => 125.6128]);
        $near = ($this->offer)($trucker, ['origin' => 'Opol', 'origin_lat' => 8.5100, 'origin_lng' => 124.5700]);

        $this->actingAs($user)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $near->getKey())
            ->assertJsonPath('data.1.id', $far->getKey());
    });

    it('does not sort by a pin from last week', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trucker->update([
            'latitude' => 8.4856,
            'longitude' => 124.5808,
            'located_at' => now()->subDays(3),
        ]);

        $first = ($this->offer)($trucker, ['origin' => 'Davao', 'origin_lat' => 7.0731, 'origin_lng' => 125.6128]);
        ($this->offer)($trucker, ['origin' => 'Opol', 'origin_lat' => 8.5100, 'origin_lng' => 124.5700]);

        $this->actingAs($user)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonPath('data.0.distance_from_m', null)
            ->assertJsonCount(2, 'data');

        expect($first->refresh()->status)->toBe(StatusValue::Pending);
    });

    it('shows nothing to somebody whose only truck is in the shop', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trucker->vehicles()->first()->update(['status' => StatusValue::Maintenance->value]);

        $this->actingAs($user)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.can_take_work', false);
    });
});

describe('taking a job', function (): void {
    it('makes the run theirs, actionable, and their own business', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->offer)($trucker);

        $this->actingAs($user)
            ->postJson("/api/v1/partner/jobs/{$trip->id}/accept")
            ->assertCreated();

        $trip->refresh();

        expect($trip->trucker_id)->toBe($trucker->getKey())
            ->and($trip->trucker_vehicle_id)->toBeNull()
            ->and($trip->status)->toBe(StatusValue::Assigned)
            ->and($trip->booking_source)->toBe(BookingSource::Direct);
    });

    it('refuses a job nobody has offered them', function (): void {
        [$user] = ($this->partner)('Boyet');
        $trip = ($this->load)();

        $this->actingAs($user)
            ->postJson("/api/v1/partner/jobs/{$trip->id}/accept")
            ->assertStatus(409);

        expect($trip->refresh()->trucker_id)->toBeNull();
    });

    it('refuses a job offered to somebody else', function (): void {
        [$user] = ($this->partner)('Boyet');
        [, $other] = ($this->partner)('Liza');

        $trip = ($this->offer)($other);

        $this->actingAs($user)
            ->postJson("/api/v1/partner/jobs/{$trip->id}/accept")
            ->assertStatus(409);

        expect($trip->refresh()->trucker_id)->toBe($other->getKey());
    });

    it('stays the fleet work when the desk hands it out', function (): void {
        [, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->load)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk();

        expect($trip->refresh()->booking_source)->toBe(BookingSource::CargoRush);
    });

    it('refuses somebody who has not been approved', function (): void {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);
        $trucker = Trucker::factory()->create(['user_id' => $user->getKey()]);
        TruckerVehicle::factory()->create(['trucker_id' => $trucker->getKey()]);

        $trip = ($this->offer)($trucker->refresh()->load('vehicles'));

        $this->actingAs($user)
            ->postJson("/api/v1/partner/jobs/{$trip->id}/accept")
            ->assertForbidden();

        expect($trip->refresh()->status)->toBe(StatusValue::Pending);
    });

    it('refuses somebody who is offline', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->offer)($trucker);

        $trucker->update(['is_online' => false]);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertStatus(422);
    });

    it('refuses a load the truck cannot carry', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet', capacityKg: 5_000);
        $trip = ($this->offer)($trucker, ['weight_kg' => 10_000]);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertStatus(422);
    });
});

describe('running it', function (): void {
    beforeEach(function (): void {
        [$this->user, $this->trucker] = ($this->partner)('Boyet');
        $this->trip = ($this->offer)($this->trucker);

        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/jobs/{$this->trip->id}/accept")
            ->assertCreated();
    });

    it('rolls out without a pre-trip check', function (): void {
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::InTransit->value);

        expect($this->trip->refresh()->dispatchRecord)->not->toBeNull();
    });

    it('shows on their own queue and nobody else', function (): void {
        [$other] = ($this->partner)('Liza');

        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/trips')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($other)
            ->getJson('/api/v1/partner/trips')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('answers 204 when they are between jobs', function (): void {
        $this->actingAs($this->user)->getJson('/api/v1/partner/trips/current')->assertNoContent();
    });

    it('hands over with the proof, and closes the run out', function (): void {
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$this->trip->id}/start")->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/deliver", [
                'receiver_name' => 'Mrs Uy',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Delivered->value);

        $this->trip->refresh();

        expect($this->trip->deliveryLog->receiver_name)->toBe('Mrs Uy')
            ->and($this->trip->isBilled())->toBeTrue()
            ->and($this->trucker->refresh()->trips_completed)->toBe(1);
    });

    it('will not let a partner touch a run that is not theirs', function (): void {
        [$other] = ($this->partner)('Liza');

        $this->actingAs($other)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/start")
            ->assertNotFound();

        $this->actingAs($other)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/deliver", ['receiver_name' => 'Nobody'])
            ->assertNotFound();
    });
});

describe('the desk handing work out', function (): void {
    it('assigns, and tells the partner', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->load)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Assigned->value);

        expect(NotificationItem::query()
            ->where('user_id', $user->getKey())
            ->where('title', 'A job has been assigned to you')
            ->exists())->toBeTrue();
    });

    it('will not hand work to somebody unapproved', function (): void {
        $trucker = Trucker::factory()->create();
        TruckerVehicle::factory()->create(['trucker_id' => $trucker->getKey()]);

        $trip = ($this->load)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertStatus(422);
    });

    it('will not put a partner on a run one of its own drivers has', function (): void {
        [, $trucker] = ($this->partner)('Boyet');
        $driver = Driver::query()->firstOrFail();

        $trip = ($this->load)(['driver_id' => $driver->getKey()]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertStatus(422);
    });

    it('releases a run back onto the board', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->load)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/trips/{$trip->id}/assign-trucker")
            ->assertOk();

        $trip->refresh();

        expect($trip->trucker_id)->toBeNull()
            ->and($trip->status)->toBe(StatusValue::Pending);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/trips?hauled_by=company')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('will not release a run the money has already moved on', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->offer)($trucker);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($user)
            ->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/trips/{$trip->id}/assign-trucker")
            ->assertStatus(422);

        expect($trip->refresh()->trucker_id)->toBe($trucker->getKey());
    });

    it('labels every run on the board as company or trucker work', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $driver = Driver::query()->firstOrFail();

        $own = ($this->load)(['driver_id' => $driver->getKey()]);
        $handed = ($this->load)();
        $taken = ($this->offer)($trucker);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$handed->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk();

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$taken->id}/accept")->assertCreated();

        $board = collect($this->actingAs($this->admin)->getJson('/api/v1/trips')->assertOk()->json('data'))
            ->keyBy('id');

        expect($board[$own->id]['hauled_by'])->toBe('company')
            ->and($board[$own->id]['booking_source'])->toBe('cargo_rush');

        expect($board[$handed->id]['hauled_by'])->toBe('trucker')
            ->and($board[$handed->id]['booking_source'])->toBe('cargo_rush')
            ->and($board[$handed->id]['trucker_name'])->toBe('Boyet')
            ->and($board[$handed->id]['trucker_plate'])->toBeNull();

        expect($board[$taken->id]['hauled_by'])->toBe('trucker')
            ->and($board[$taken->id]['booking_source'])->toBe('direct');
    });

    it('does not let a partner run read as unassigned', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->offer)($trucker);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/trips')->assertOk()->json('data'))
            ->firstWhere('id', $trip->id);

        expect($row['driver_name'])->toBeNull()
            ->and($row['vehicle_plate'])->toBeNull()
            ->and($row['hauled_by'])->toBe('trucker')
            ->and($row['trucker_name'])->toBe('Boyet');
    });

    it('filters the board by who is hauling', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $driver = Driver::query()->firstOrFail();

        ($this->load)(['driver_id' => $driver->getKey()]);
        ($this->load)();
        $taken = ($this->offer)($trucker);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$taken->id}/accept")->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/trips?hauled_by=trucker')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taken->id);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/trips?hauled_by=company')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters the board by where the work came from', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');

        $handed = ($this->load)();
        $taken = ($this->offer)($trucker);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$handed->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk();
        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$taken->id}/accept")->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/trips?booking_source=direct')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taken->id);
    });

    it('lists who is actually available to be given one', function (): void {
        ($this->partner)('Boyet');
        [, $offline] = ($this->partner)('Liza');
        $offline->update(['is_online' => false]);
        [, $noTruck] = ($this->partner)('Rey');
        $noTruck->vehicles()->first()->update(['status' => StatusValue::Maintenance->value]);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/truckers/available')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Boyet');
    });
});
