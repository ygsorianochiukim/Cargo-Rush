<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\TripTicketService;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerDriver;
use App\Domain\Trucker\Models\TruckerVehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * A trucker's own drivers — added by the owner, each with their own login.
 *
 * The rule that matters is separation, from every side. A trucker's driver is
 * never one of Cargo Rush's drivers: not in Drivers Management, not on the
 * trip board, not able to reach the owner's money or another trucker's runs.
 * They see exactly the runs their owner handed them, and every screen that
 * lists them says who they drive for.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();

    /** An owner with a login and a truck, approved unless told otherwise. */
    $this->owner = function (string $business, string $status = 'active'): array {
        $user = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);

        $trucker = Trucker::factory()->create([
            'user_id' => $user->getKey(),
            'business_name' => $business,
            'status' => $status,
        ]);

        TruckerVehicle::factory()->create(['trucker_id' => $trucker->getKey()]);

        return [$user, $trucker->refresh()->load('vehicles')];
    };

    $this->addDriver = fn (User $owner, array $overrides = []) => $this->actingAs($owner)
        ->postJson('/api/v1/partner/drivers', [
            'name' => 'Nonoy Bacalso',
            'phone' => '0917 222 3333',
            'licence_no' => 'N05-11-222333',
            'email' => 'rico@example.ph',
            'password' => 'long-haul-2026',
            ...$overrides,
        ]);

    /** A run the owner has taken and not started. */
    $this->run = fn (Trucker $trucker, array $overrides = []) => Trip::create([
        'customer_id' => $this->customer->getKey(),
        'origin' => 'Iponan',
        'destination' => 'Bukidnon',
        'cargo' => 'Rice, 200 sacks',
        'weight_kg' => 5_000,
        'status' => StatusValue::Assigned->value,
        'scheduled_at' => now()->addDay(),
        'price_cents' => 1_000_000,
        'currency' => 'PHP',
        'trucker_id' => $trucker->getKey(),
        'trucker_vehicle_id' => $trucker->vehicles->first()->getKey(),
        'booking_source' => BookingSource::Direct->value,
        ...$overrides,
    ]);

    /** Every checklist item ticked. */
    $this->allPass = fn (): array => array_fill_keys(
        ['tires', 'oil', 'gears', 'brakes', 'lights', 'coolant', 'documents'],
        true,
    );

    [$this->ownerUser, $this->trucker] = ($this->owner)('Aquino Trucking Services');
});

describe('the owner adding drivers', function (): void {
    it('adds a driver with a login of their own, labelled with who they drive for', function (): void {
        ($this->addDriver)($this->ownerUser)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nonoy Bacalso')
            ->assertJsonPath('data.email', 'rico@example.ph')
            ->assertJsonPath('data.status', StatusValue::Active->value)
            ->assertJsonPath('data.employer.kind', 'trucker')
            ->assertJsonPath('data.employer.label', 'Aquino Trucking Services');

        $login = User::where('email', 'rico@example.ph')->firstOrFail();

        expect($login->role)->toBe(Role::TruckerDriver->value)
            ->and($login->company_id)->toBe($this->company->getKey());

        $this->actingAs($this->ownerUser)
            ->getJson('/api/v1/partner/drivers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('makes an owner still waiting on approval wait to add drivers', function (): void {
        [$pending] = ($this->owner)('New Trucking', StatusValue::Pending->value);

        ($this->addDriver)($pending)->assertForbidden();

        expect(User::where('email', 'rico@example.ph')->exists())->toBeFalse();
    });

    it('refuses the same licence twice for one owner, in a sentence', function (): void {
        ($this->addDriver)($this->ownerUser)->assertCreated();
        ($this->addDriver)($this->ownerUser, ['email' => 'rico2@example.ph'])->assertStatus(422);
    });

    it('asks for a licence and a login', function (): void {
        ($this->addDriver)($this->ownerUser, ['licence_no' => '', 'email' => '', 'password' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['licence_no', 'email', 'password']);
    });

    it('lists only their own drivers', function (): void {
        [$rivalUser] = ($this->owner)('Tan Hauling');
        ($this->addDriver)($rivalUser, ['email' => 'liza@example.ph', 'licence_no' => 'N09-00-000001'])->assertCreated();

        $this->actingAs($this->ownerUser)
            ->getJson('/api/v1/partner/drivers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('handing a run to a driver', function (): void {
    beforeEach(function (): void {
        $this->driverId = ($this->addDriver)($this->ownerUser)->json('data.id');
        $this->driverUser = User::where('email', 'rico@example.ph')->firstOrFail();
        $this->trip = ($this->run)($this->trucker);
    });

    it('shows the driver only the runs they were handed', function (): void {
        // Another of the owner's runs, not handed to anybody.
        ($this->run)($this->trucker);

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId])
            ->assertOk()
            ->assertJsonPath('data.trucker_driver_id', $this->driverId)
            ->assertJsonPath('data.trucker_driver_name', 'Nonoy Bacalso');

        $this->actingAs($this->driverUser)
            ->getJson('/api/v1/crew/trips')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->trip->id);

        // The owner still sees both.
        $this->actingAs($this->ownerUser)->getJson('/api/v1/partner/trips')->assertJsonCount(2, 'data');
    });

    it('lets the driver run it end to end, and credits the owner', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId])
            ->assertOk();

        // The pre-trip check first, as a Cargo Rush driver would.
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/inspection", ['results' => ($this->allPass)()])
            ->assertCreated()
            ->assertJsonPath('meta.good_to_go', true);

        $this->actingAs($this->driverUser)->postJson("/api/v1/crew/trips/{$this->trip->id}/start")->assertOk();

        // In the Tracking screen's shape: progress, position, both ends.
        $this->actingAs($this->driverUser)->getJson('/api/v1/crew/trips/current')
            ->assertOk()
            ->assertJsonPath('data.id', $this->trip->id)
            ->assertJsonPath('data.progress_pct', 0)
            ->assertJsonPath('data.inspection.passed', true)
            ->assertJsonPath('data.inspection.checked_by', 'Nonoy Bacalso')
            ->assertJsonPath('data.vehicle_plate', $this->trucker->vehicles->first()->plate);

        // And the map's position reports land on the run.
        $this->actingAs($this->driverUser)
            ->postJson('/api/v1/gps/pings', [
                'trip_id' => $this->trip->id,
                'location' => 'Villanueva',
                'lat' => 8.58, 'lng' => 124.77,
                'speed_kph' => 55,
                'progress_pct' => 40,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertSuccessful();

        $this->actingAs($this->driverUser)->getJson("/api/v1/gps/trips/{$this->trip->id}/tracking")->assertOk();

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Delivered->value);

        expect($this->trip->refresh()->isBilled())->toBeTrue()
            ->and($this->trucker->refresh()->trips_completed)->toBe(1);
    });

    it('will not let a driver start a run that was not handed to them', function (): void {
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/start")
            ->assertNotFound();
    });

    it('will not hand a run to another trucker\'s driver', function (): void {
        [$rivalUser] = ($this->owner)('Tan Hauling');
        $theirs = ($this->addDriver)($rivalUser, ['email' => 'liza@example.ph', 'licence_no' => 'N09-00-000001'])->json('data.id');

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $theirs])
            ->assertNotFound();

        expect($this->trip->refresh()->trucker_driver_id)->toBeNull();
    });

    it('will not swap the driver once the run is on the road', function (): void {
        $this->trip->update(['status' => StatusValue::InTransit->value]);

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId])
            ->assertUnprocessable();
    });

    it('takes a stood-down driver off their unstarted runs, and off the road', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId])
            ->assertOk();

        $this->actingAs($this->ownerUser)
            ->patchJson("/api/v1/partner/drivers/{$this->driverId}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Inactive->value);

        expect($this->trip->refresh()->trucker_driver_id)->toBeNull();

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId])
            ->assertUnprocessable();
    });

    it('gives the driver the same checklist a Cargo Rush driver answers', function (): void {
        $this->actingAs($this->driverUser)
            ->getJson('/api/v1/crew/inspections/checklist')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.key', 'tires');
    });

    it('lets the driver answer the dispatch checklist, and prints it', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId]);

        $this->actingAs($this->driverUser)->getJson('/api/v1/crew/dispatch-checklist')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.key', 'licence');

        $answers = array_fill_keys(TripTicketService::keys(), 'yes');

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/dispatch-checklist", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('data.dispatch_checklist.checked_by', 'Nonoy Bacalso');

        expect($this->trip->refresh()->dispatch_checklist['briefing'])->toBe('yes');
    });

    it('will not let the driver start before the pre-trip check', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId]);

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/start")
            ->assertUnprocessable();

        expect($this->trip->refresh()->status)->toBe(StatusValue::Assigned);
    });

    it('holds the truck on a failed check, and tells the owner rather than Cargo Rush', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId]);

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/inspection", ['results' => [...($this->allPass)(), 'brakes' => false]])
            ->assertCreated()
            ->assertJsonPath('meta.good_to_go', false);

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/start")
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'brakes'));

        expect(NotificationItem::query()->where('title', 'A truck failed its pre-trip check')->pluck('user_id')->all())
            ->toBe([$this->ownerUser->id]);
    });

    it('keeps a trucker\'s checks out of Cargo Rush\'s inspection log', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId]);
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/inspection", ['results' => ($this->allPass)()])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/inspections')
            ->assertOk()
            ->assertJsonMissing(['trip_id' => $this->trip->id]);
    });

    it('will not take a check for a run that was not handed to them', function (): void {
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/inspection", ['results' => ($this->allPass)()])
            ->assertNotFound();
    });

    it('stops the driver when the office stops the owner', function (): void {
        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId])
            ->assertOk();

        $this->trucker->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/start")
            ->assertForbidden();

        $this->actingAs($this->driverUser)
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.crew_may_drive', false);
    });
});

describe('the owner running a crew', function (): void {
    beforeEach(function (): void {
        $this->driverId = ($this->addDriver)($this->ownerUser)->json('data.id');
        $this->driverUser = User::where('email', 'rico@example.ph')->firstOrFail();
        $this->trip = ($this->run)($this->trucker);
        $this->hand = fn (array $body) => $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => $this->driverId, ...$body]);
    });

    it('sends the run out on the truck the owner picks', function (): void {
        $second = TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->id, 'status' => 'available']);

        ($this->hand)(['trucker_vehicle_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.trucker_vehicle_id', $second->id);
    });

    it('will not send a run out on a truck in the shop, or somebody else\'s', function (): void {
        $shop = TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->id, 'status' => 'maintenance']);
        [, $rival] = ($this->owner)('Tan Hauling');

        ($this->hand)(['trucker_vehicle_id' => $shop->id])->assertUnprocessable();
        ($this->hand)(['trucker_vehicle_id' => $rival->vehicles->first()->id])->assertNotFound();
    });

    it('tells the driver they have a new run, and tells them when it is taken back', function (): void {
        ($this->hand)([])->assertOk();

        expect(NotificationItem::query()->where('user_id', $this->driverUser->id)->where('title', 'New run for you')->count())->toBe(1);

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/driver", ['trucker_driver_id' => null])
            ->assertOk();

        expect(NotificationItem::query()->where('user_id', $this->driverUser->id)->where('title', 'A run was taken off your list')->count())->toBe(1);
    });

    it('will not let the owner start a run handed to a driver', function (): void {
        ($this->hand)([])->assertOk();

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/start")
            ->assertUnprocessable();

        expect($this->trip->refresh()->status)->toBe(StatusValue::Assigned);
    });

    it('asks for a fresh check when the truck changes after one passed', function (): void {
        ($this->hand)([])->assertOk();
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/inspection", ['results' => ($this->allPass)()])
            ->assertCreated();

        $second = TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->id, 'status' => 'available']);
        ($this->hand)(['trucker_vehicle_id' => $second->id])->assertOk();

        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/crew/trips/{$this->trip->id}/start")
            ->assertUnprocessable();
    });

    it('removes a driver: their runs come back and their login stops', function (): void {
        ($this->hand)([])->assertOk();
        $token = $this->driverUser->createToken('phone')->plainTextToken;

        $this->actingAs($this->ownerUser)
            ->deleteJson("/api/v1/partner/drivers/{$this->driverId}")
            ->assertNoContent();

        expect($this->trip->refresh()->trucker_driver_id)->toBeNull()
            ->and($this->driverUser->tokens()->count())->toBe(0);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/crew/trips')->assertUnauthorized();

        $this->actingAs($this->ownerUser)->getJson('/api/v1/partner/drivers')->assertJsonCount(0, 'data');
    });

    it('lets the owner correct a driver\'s details', function (): void {
        $this->actingAs($this->ownerUser)
            ->patchJson("/api/v1/partner/drivers/{$this->driverId}", ['name' => 'Nonoy B. Bacalso', 'phone' => '0918 111 2222'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nonoy B. Bacalso')
            ->assertJsonPath('data.phone', '0918 111 2222');
    });

    it('refuses to correct a licence into one another of their drivers has, in a sentence', function (): void {
        ($this->addDriver)($this->ownerUser, ['email' => 'second@example.ph', 'licence_no' => 'N07-00-000007'])->assertCreated();

        $this->actingAs($this->ownerUser)
            ->patchJson("/api/v1/partner/drivers/{$this->driverId}", ['licence_no' => 'N07-00-000007'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('licence_no');
    });

    it('shows the office board which of the trucker\'s drivers is on the run', function (): void {
        ($this->hand)([])->assertOk();

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/trips?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->trip->id);

        expect($row['trucker_driver_name'])->toBe('Nonoy Bacalso')
            ->and($row['trucker_business_name'])->toBe('Aquino Trucking Services');
    });

    it('says on the job board when an approved owner has no truck on the road', function (): void {
        $this->trucker->vehicles()->update(['status' => 'maintenance']);

        $this->actingAs($this->ownerUser)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonPath('meta.has_truck', false);
    });

    it('only lets a driver report positions on their own run', function (): void {
        $ping = fn () => [
            'trip_id' => $this->trip->id,
            'location' => 'Villanueva',
            'lat' => 8.58, 'lng' => 124.77,
            'speed_kph' => 55,
            'progress_pct' => 40,
            'recorded_at' => now()->toIso8601String(),
        ];

        // Not handed to them yet.
        $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', $ping())->assertNotFound();

        ($this->hand)([])->assertOk();

        $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', $ping())->assertSuccessful();
    });
});

describe('which truck takes it — the trucker\'s choice', function (): void {
    beforeEach(function (): void {
        // One truck too small for a 5,000 kg load, one big enough.
        $this->trucker->vehicles()->update(['capacity_kg' => 1_200]);
        $this->big = TruckerVehicle::factory()->create([
            'trucker_id' => $this->trucker->id, 'capacity_kg' => 18_000, 'status' => 'available',
        ]);
        $this->trucker->update(['is_online' => true]);

        $this->request = fn (int $kg) => Trip::create([
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Malanang', 'destination' => 'Mambuaya', 'cargo' => 'Rice',
            'weight_kg' => $kg, 'status' => StatusValue::Pending->value,
            'scheduled_at' => now()->addDay(), 'price_cents' => 482_300, 'currency' => 'PHP',
        ]);
        $this->give = fn (Trip $trip) => $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $this->trucker->id]);
    });

    it('lets the desk give a load to a trucker whose second truck fits, without picking one', function (): void {
        $trip = ($this->request)(5_000);

        ($this->give)($trip)->assertOk();

        expect($trip->refresh()->trucker_id)->toBe($this->trucker->id)
            ->and($trip->trucker_vehicle_id)->toBeNull();
    });

    it('refuses the desk when none of the trucker\'s trucks can carry it', function (): void {
        ($this->give)(($this->request)(40_000))->assertUnprocessable();
    });

    it('fills in the truck at Start when only one of theirs fits', function (): void {
        $trip = ($this->request)(5_000);
        ($this->give)($trip)->assertOk();

        $this->actingAs($this->ownerUser)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();

        expect($trip->refresh()->trucker_vehicle_id)->toBe($this->big->id);
    });

    it('asks the trucker to choose at Start when two or more fit', function (): void {
        $trip = ($this->request)(1_000);
        ($this->give)($trip)->assertOk();

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$trip->id}/start")
            ->assertUnprocessable();

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$trip->id}/driver", [
                'trucker_driver_id' => null, 'trucker_vehicle_id' => $this->big->id,
            ])
            ->assertOk();

        $this->actingAs($this->ownerUser)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
    });

    it('will not let the trucker pick a truck too small for the load', function (): void {
        $trip = ($this->request)(5_000);
        ($this->give)($trip)->assertOk();

        $this->actingAs($this->ownerUser)
            ->postJson("/api/v1/partner/trips/{$trip->id}/driver", [
                'trucker_driver_id' => null,
                'trucker_vehicle_id' => $this->trucker->vehicles()->where('capacity_kg', 1_200)->value('id'),
            ])
            ->assertUnprocessable();
    });
});

describe('separation from Cargo Rush', function (): void {
    beforeEach(function (): void {
        ($this->addDriver)($this->ownerUser)->assertCreated();
        $this->driverUser = User::where('email', 'rico@example.ph')->firstOrFail();
    });

    it('keeps a trucker\'s driver out of Drivers Management', function (): void {
        $names = $this->actingAs($this->admin)
            ->getJson('/api/v1/drivers?per_page=100')
            ->assertOk()
            ->json('data.*.name');

        expect($names)->not->toContain('Nonoy Bacalso');
    });

    it('labels every Cargo Rush driver as the fleet\'s', function (): void {
        $kinds = $this->actingAs($this->admin)
            ->getJson('/api/v1/drivers?per_page=100')
            ->json('data.*.employer.kind');

        expect($kinds)->not->toBeEmpty()
            ->and(array_unique($kinds))->toBe(['fleet']);
    });

    it('shows the office the owner\'s drivers, labelled as theirs', function (): void {
        $this->actingAs($this->admin)
            ->getJson("/api/v1/truckers/{$this->trucker->id}")
            ->assertOk()
            ->assertJsonPath('data.drivers.0.name', 'Nonoy Bacalso')
            ->assertJsonPath('data.drivers.0.employer.kind', 'trucker');
    });

    it('gives the driver none of the owner\'s screens, and none of the fleet\'s', function (): void {
        foreach (['partner/me', 'partner/wallet', 'partner/jobs', 'partner/drivers', 'partner/vehicles', 'trips', 'drivers'] as $path) {
            $this->actingAs($this->driverUser)->getJson("/api/v1/{$path}")->assertForbidden();
        }
    });

    it('says on GET /me who they drive for', function (): void {
        $this->actingAs($this->driverUser)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', Role::TruckerDriver->value)
            ->assertJsonPath('data.crew_employer', 'Aquino Trucking Services')
            ->assertJsonPath('data.crew_may_drive', true);
    });

    it('gives the owner no way to reach the crew endpoints', function (): void {
        $this->actingAs($this->ownerUser)->getJson('/api/v1/crew/trips')->assertForbidden();
    });

    it('stores them as trucker drivers, never as Cargo Rush drivers', function (): void {
        expect(TruckerDriver::query()->where('name', 'Nonoy Bacalso')->exists())->toBeTrue()
            ->and(Driver::query()->where('name', 'Nonoy Bacalso')->exists())->toBeFalse();
    });
});
