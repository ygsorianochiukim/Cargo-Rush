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

/**
 * What a partner is shown, and what they may take.
 *
 * **There is no open board**, and most of this file is that rule from every
 * angle. A trucker sees a run only because somebody put their name on it — a
 * customer picking them off the hauler list, or the desk handing it to them —
 * so what is tested here is mainly *absence*: the loads that must not appear,
 * and the people who must not be able to take one.
 *
 * The rule is enforced twice on purpose. The list hides what was not offered,
 * and the accept refuses it outright; a screen that hides a row while the
 * endpoint still honours the id is not a rule, it is a decoration.
 *
 * The other half is the fork that decides the money. A partner accepting a run
 * a customer chose them for is doing their own business and bills the customer
 * (`direct`); one the desk hands a load to is hauling the fleet's work and is
 * paid out of it (`cargo_rush`). Both are the same percentage in opposite
 * directions, so which one a run records is not a detail.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();

    /** A vetted partner with a login, online, with one working truck. */
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

    /** A customer's request nobody has placed yet. Invisible to every partner. */
    $this->load = fn (array $overrides = []) => Trip::create([
        'customer_id' => $this->customer->getKey(),
        'origin' => 'Iponan',
        'destination' => 'Bukidnon',
        'cargo' => 'Rice, 200 sacks',
        'weight_kg' => 10_000,
        'status' => StatusValue::Pending->value,
        // Not nullable on `trips`, so every fixture carries one. The board does
        // not read it; it is the desk's column and is here to make a row legal.
        'scheduled_at' => now()->addDay(),
        'price_cents' => 1_000_000,
        'currency' => 'PHP',
        ...$overrides,
    ]);

    /**
     * A request a customer held for one partner by name.
     *
     * The only kind of row that ever reaches a trucker's screen, so it is where
     * most of these tests start — see `JobBoardService::open()`.
     */
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
            // The figure that actually lands with them. A board quoting the
            // gross would be quoting a number nobody receives.
            ->assertJsonPath('data.0.your_take_cents', 880_000);
    });

    it('never shows work nobody has offered them', function (): void {
        // The rule this screen is built on. A request the office has not placed
        // is the office's to place: a customer who asked Cargo Rush to carry
        // something asked *Cargo Rush*, and quietly putting that load in front
        // of every contractor is not the deal they made.
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

        // Offered to them, then crewed in-house before they answered. The
        // offer is off the table and must not still be pressable.
        ($this->offer)($trucker, ['driver_id' => $driver->getKey()]);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('hides a job that has already been confirmed', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        ($this->offer)($trucker, ['status' => StatusValue::Assigned->value]);

        // A run stops being on the board by ceasing to be `pending`. There is
        // no cancellation step anywhere, which is the point of building the
        // board as a view rather than a queue.
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

        // A load that needs a freezer is never offered to a flatbed.
        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows a load that names no kind of truck at all', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');

        $flatbed = TruckCategory::create([
            'key' => 'flatbed',
            'name' => 'Flatbed',
        ]);
        $trucker->vehicles()->first()->update(['truck_category_id' => $flatbed->getKey()]);

        // An unstated requirement is not a requirement. Refusing on it would
        // empty the board for every run somebody booked in a hurry.
        ($this->offer)($trucker, ['truck_category_id' => null]);

        $this->actingAs($user)->getJson('/api/v1/partner/jobs')->assertOk()->assertJsonCount(1, 'data');
    });

    it('does not put work the desk assigned them back on the board', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->load)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->getKey()])
            ->assertOk();

        // Assigning is a decision rather than an offer: the run is already
        // theirs, with nothing left to accept, so it belongs on their queue and
        // not on a board of work to choose from. It also pays the other way
        // round, and a board where two cards meant different deals would be a
        // board nobody could read.
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
        // Online, but the position is three days old. Sorting by it would send
        // a load to wherever they happened to be on Tuesday.
        $trucker->update([
            'latitude' => 8.4856,
            'longitude' => 124.5808,
            'located_at' => now()->subDays(3),
        ]);

        $first = ($this->offer)($trucker, ['origin' => 'Davao', 'origin_lat' => 7.0731, 'origin_lng' => 125.6128]);
        ($this->offer)($trucker, ['origin' => 'Opol', 'origin_lat' => 8.5100, 'origin_lng' => 124.5700]);

        // Unsorted, so the order is the one the query gave — and neither row
        // carries a distance it cannot justify.
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
            ->and($trip->trucker_vehicle_id)->not->toBeNull()
            // Straight to `assigned`: accepting supplies the four things the
            // desk's confirmation exists to supply.
            ->and($trip->status)->toBe(StatusValue::Assigned)
            // The customer chose them, so they bill the customer for it.
            ->and($trip->booking_source)->toBe(BookingSource::Direct);
    });

    it('refuses a job nobody has offered them', function (): void {
        // The other half of "no open board": not merely hidden from the list,
        // but refused outright to somebody who has the id anyway. A screen that
        // hides a row while the endpoint still honours it is not a rule.
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

        // The same 409, in the same words, as a job that does not exist:
        // somebody holding an id they were never offered learns nothing from
        // the difference.
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

        // The haulier quoted and will invoice, so the partner is paid out of
        // that rather than charged. These two lines and the `direct` above are
        // the whole of the money fork — nothing else writes this column.
        expect($trip->refresh()->booking_source)->toBe(BookingSource::CargoRush);
    });

    it('refuses somebody who has not been approved', function (): void {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);
        $trucker = Trucker::factory()->create(['user_id' => $user->getKey()]);
        TruckerVehicle::factory()->create(['trucker_id' => $trucker->getKey()]);

        // Offered to them all the same — a customer could have picked them
        // before the office got round to reading the licence. Vetting is the
        // gate, not the offer.
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
        // A driver cannot leave without one; a partner can. The gate is the
        // haulier inspecting the haulier's own unit, and a partner's truck is
        // not the haulier's to clear.
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::InTransit->value);

        // The dispatch record is still written, so Dispatch Monitoring shows no
        // gap for a run that plainly left.
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

        // The same answer as an id that does not exist. A partner holding a
        // trip id they were never given must learn nothing from the difference.
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

        // And it is back with the office, not back on anybody's board. There is
        // no open board to return it to: a request with no name on it is the
        // desk's to place again.
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

        // Unpicking a wallet entry and an invoice is a correction the office
        // makes deliberately, not a side effect of clearing a name off a row.
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

        // The fleet's own crew.
        expect($board[$own->id]['hauled_by'])->toBe('company')
            ->and($board[$own->id]['booking_source'])->toBe('cargo_rush');

        // A contractor the desk handed it to: still the fleet's to invoice.
        expect($board[$handed->id]['hauled_by'])->toBe('trucker')
            ->and($board[$handed->id]['booking_source'])->toBe('cargo_rush')
            ->and($board[$handed->id]['trucker_name'])->toBe('Boyet')
            ->and($board[$handed->id]['trucker_plate'])->not->toBeNull();

        // A contractor who found it themselves: theirs to invoice.
        expect($board[$taken->id]['hauled_by'])->toBe('trucker')
            ->and($board[$taken->id]['booking_source'])->toBe('direct');
    });

    it('does not let a partner run read as unassigned', function (): void {
        // The failure this column exists to prevent. A partner's run has no
        // driver, no helper and no vehicle — none of those are the fleet's —
        // so from the crew fields alone it is indistinguishable from work
        // nobody has picked up, which is the one thing a dispatcher must not
        // be told by mistake.
        [$user, $trucker] = ($this->partner)('Boyet');
        $trip = ($this->offer)($trucker);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/trips')->assertOk()->json('data'))
            ->firstWhere('id', $trip->id);

        expect($row['driver_name'])->toBeNull()
            ->and($row['vehicle_plate'])->toBeNull()
            // …and yet the board knows exactly who has it.
            ->and($row['hauled_by'])->toBe('trucker')
            ->and($row['trucker_name'])->toBe('Boyet');
    });

    it('filters the board by who is hauling', function (): void {
        [$user, $trucker] = ($this->partner)('Boyet');
        $driver = Driver::query()->firstOrFail();

        ($this->load)(['driver_id' => $driver->getKey()]);
        // Booked but not yet crewed: still the company's work, not a partner's.
        ($this->load)();
        $taken = ($this->offer)($trucker);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$taken->id}/accept")->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/trips?hauled_by=trucker')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taken->id);

        // `company` is the absence of a partner rather than the presence of a
        // driver — a run booked before anybody was named is still the fleet's.
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

        // The audit cut: everything a contractor billed themselves.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/trips?booking_source=direct')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taken->id);
    });

    it('lists who is actually available to be given one', function (): void {
        ($this->partner)('Boyet');
        // Approved but offline, and approved with the only truck in the shop:
        // neither belongs in the desk assign dialog.
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
