<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * A customer asking for a pickup, and the desk confirming it.
 *
 * This is the half of the workflow that did not exist: work could only be
 * booked by somebody at the desk, so a customer's request arrived as a phone
 * call and lived in whoever took it. The two things these pin are the scope —
 * a customer can only ever see and touch their own account — and the fact that
 * `pending` genuinely means "nobody has decided about this yet", which is what
 * makes the confirmation step real rather than decorative.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->helper = Driver::where('name', 'Jun Abad')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    // Seeded by FleetSeeder against Negros Fresh Mart, so the account has a
    // firm behind it — a customer login without one has nothing to show.
    $this->buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();
    $this->firm = Customer::findOrFail($this->buyer->customer_id);

    $this->request = [
        'origin' => 'Bacolod',
        'destination' => 'Iloilo',
        'cargo' => 'Chilled produce, 8 crates',
        'weight_kg' => 1800,
        'preferred_at' => now()->addDay()->toIso8601String(),
    ];

    $this->file = fn (array $overrides = []) => $this->actingAs($this->buyer)
        ->postJson('/api/v1/portal/requests', [...$this->request, ...$overrides]);
});

describe('a customer files a request', function (): void {
    it('lands as pending, against their own firm, with a reference', function (): void {
        $response = ($this->file)()->assertCreated();

        expect($response->json('data.status'))->toBe(StatusValue::Pending->value)
            ->and($response->json('data.reference'))->toStartWith('CR-')
            ->and($response->json('data.customer_id'))->toBe($this->firm->id)
            // Nobody is on it yet. That is the point of a request.
            ->and($response->json('data.driver_id'))->toBeNull()
            ->and($response->json('data.vehicle_id'))->toBeNull();
    });

    it('quotes a price on the spot', function (): void {
        // The difference between a request and a hopeful message: the customer
        // leaves the form knowing what it will cost, because there is nobody to
        // ring them back with a figure.
        expect(($this->file)()->json('data.price_cents'))->toBeGreaterThan(0);
    });

    it('records who asked, not just which firm', function (): void {
        $id = ($this->file)()->json('data.id');

        expect(Trip::findOrFail($id)->requested_by)->toBe($this->buyer->id);
    });

    it('tells the desk, and not the drivers', function (): void {
        ($this->file)();

        $told = NotificationItem::query()
            ->where('title', 'New delivery request')
            ->pluck('user_id');

        // Only the people who can act on it. A fleet-wide row (a null user)
        // would alert every driver about work none of them can confirm.
        expect($told)->toContain($this->admin->id)
            ->not->toContain($this->marco->id)
            ->and($told->contains(null))->toBeFalse();
    });

    it('takes the two pins the customer dropped, and prices the distance', function (): void {
        // What the map on the request screen is actually for. The handset
        // sends the same four numbers the office form does, and the quote
        // stops being base-plus-weight for a run nobody has measured.
        $flat = ($this->file)()->json('data.price_cents');

        $pinned = ($this->file)([
            // Bacolod to Iloilo, roughly, across the strait.
            'origin_lat' => 10.6407,
            'origin_lng' => 122.9689,
            'destination_lat' => 10.7202,
            'destination_lng' => 122.5621,
        ])->assertCreated();

        $trip = Trip::findOrFail($pinned->json('data.id'));

        expect((float) $trip->origin_lat)->toBe(10.6407)
            ->and((float) $trip->destination_lng)->toBe(122.5621)
            ->and($trip->distance_total_m)->toBeGreaterThan(0)
            ->and($trip->price_cents)->toBeGreaterThan($flat);
    });

    it('refuses half a coordinate, because half a coordinate is not a place', function (): void {
        ($this->file)(['origin_lat' => 10.6407])
            ->assertStatus(422)
            ->assertJsonValidationErrors('origin_lng');
    });

    it('refuses a pickup time that has already passed', function (): void {
        ($this->file)(['preferred_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_at');
    });

    it('refuses a load that weighs nothing', function (): void {
        ($this->file)(['weight_kg' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_kg');
    });

    it('ignores a customer, a driver or a status the payload tries to choose', function (): void {
        $other = Customer::where('name', 'Metro Grocers')->firstOrFail();

        $response = ($this->file)([
            'customer_id' => $other->id,
            'driver_id' => $this->driver->id,
            'status' => StatusValue::Delivered->value,
        ])->assertCreated();

        // None of the three are in the rules, so they are stripped rather than
        // honoured — which is what stops one customer filing against another's
        // account, or a request arriving already delivered.
        expect($response->json('data.customer_id'))->toBe($this->firm->id)
            ->and($response->json('data.driver_id'))->toBeNull()
            ->and($response->json('data.status'))->toBe(StatusValue::Pending->value);
    });
});

describe('a customer reads their own work', function (): void {
    it('lists only their own deliveries', function (): void {
        ($this->file)();

        // Another firm's haul, booked by the office.
        $other = Customer::where('name', 'Metro Grocers')->firstOrFail();
        $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Manila',
            'destination' => 'Batangas',
            'cargo' => 'Dry goods',
            'weight_kg' => 3200,
            'customer_id' => $other->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $rows = $this->actingAs($this->buyer)->getJson('/api/v1/portal/requests')
            ->assertOk()
            ->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['customer_id'])->toBe($this->firm->id);
    });

    it('cannot read another firm delivery by naming it', function (): void {
        $other = Customer::where('name', 'Metro Grocers')->firstOrFail();

        $theirs = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Manila',
            'destination' => 'Batangas',
            'cargo' => 'Dry goods',
            'weight_kg' => 3200,
            'customer_id' => $other->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->json('data.id');

        // A 404, not a 403: confirming that a trip exists is itself a leak.
        $this->actingAs($this->buyer)->getJson("/api/v1/portal/requests/{$theirs}")
            ->assertNotFound();
    });

    it('leads with what they are waiting on and what they owe', function (): void {
        ($this->file)();

        $this->actingAs($this->buyer)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->assertJsonPath('data.awaiting_confirmation', 1)
            ->assertJsonPath('data.delivered', 0)
            ->assertJsonPath('data.pending_payment_cents', 0)
            ->assertJsonPath('data.successful_payment_cents', 0)
            ->assertJsonPath('data.customer.name', $this->firm->name);
    });

    it('says so plainly when the account is linked to no firm', function (): void {
        // An account somebody created and forgot to attach. Every portal
        // endpoint is scoped to the firm, so an empty list would read as a
        // customer with no deliveries — a different and more confusing thing.
        $orphan = User::create([
            'name' => 'Unattached Buyer',
            'email' => 'nobody@example.ph',
            'password' => Hash::make('password1'),
            'role' => Role::Customer->value,
        ]);

        $this->actingAs($orphan)->getJson('/api/v1/portal/summary')->assertNotFound();
        $this->actingAs($orphan)->getJson('/api/v1/portal/requests')->assertNotFound();
    });

    it('is not a door into the whole board', function (): void {
        // The customer role holds `portal.*` and nothing else. A customer
        // reaching `trips` would see every firm's work.
        expect($this->buyer->permissions())->not->toContain('trips.view');

        $keys = collect(
            $this->actingAs($this->buyer)->getJson('/api/v1/navigation?client=mobile')->json('data')
        )->pluck('key');

        expect($keys)->toContain('requests', 'request', 'invoices')
            // The driver's tabs are not theirs, and vice versa.
            ->not->toContain('tracking')
            ->not->toContain('cargo');
    });
});

describe('the desk confirms it', function (): void {
    it('moves pending to assigned with the crew, the unit and the time', function (): void {
        $id = ($this->file)()->json('data.id');
        $when = now()->addDays(2)->startOfHour();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$id}/confirm", [
                'driver_id' => $this->driver->id,
                'helper_id' => $this->helper->id,
                'vehicle_id' => $this->vehicle->id,
                'scheduled_at' => $when->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Assigned->value)
            ->assertJsonPath('data.driver_id', $this->driver->id)
            ->assertJsonPath('data.helper_id', $this->helper->id)
            ->assertJsonPath('data.vehicle_id', $this->vehicle->id);
    });

    it('tells the driver whose day just changed', function (): void {
        $id = ($this->file)()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertOk();

        expect(
            NotificationItem::where('user_id', $this->marco->id)
                ->where('title', 'Delivery assigned to you')
                ->count()
        )->toBe(1);
    });

    it('will not confirm without a driver, a unit and a time', function (): void {
        $id = ($this->file)()->json('data.id');

        // `assigned` means a driver can act on it. Confirming without these
        // would produce a run that says go and cannot be gone on.
        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['driver_id', 'vehicle_id', 'scheduled_at']);
    });

    it('will not put the same person on as driver and helper', function (): void {
        $id = ($this->file)()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $this->driver->id,
            'helper_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('helper_id');
    });

    it('re-quotes when the desk corrects the weight', function (): void {
        $id = ($this->file)()->json('data.id');
        $before = Trip::findOrFail($id)->price_cents;

        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            // The crates were heavier than the customer estimated.
            'weight_kg' => 4200,
        ])->assertOk();

        expect(Trip::findOrFail($id)->price_cents)->toBeGreaterThan($before);
    });

    it('keeps a rate the desk negotiated instead of re-deriving it', function (): void {
        $id = ($this->file)()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'price_cents' => 12_345_00,
        ])->assertOk();

        // A negotiated rate is a decision. Deriving over it would silently
        // overrule whoever made it.
        expect(Trip::findOrFail($id)->price_cents)->toBe(12_345_00);
    });

    it('refuses to confirm a run that is already on the road', function (): void {
        $id = ($this->file)()->json('data.id');
        $crew = [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ];

        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", $crew)->assertOk();
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();

        // Confirming it again would move it backwards. Amending an in-flight
        // trip is what the edit form is for.
        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", $crew)
            ->assertStatus(422);
    });

    it('is the customer own view of it too', function (): void {
        $id = ($this->file)()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertOk();

        // The customer is not told separately; they are reading the same trip.
        $this->actingAs($this->buyer)->getJson("/api/v1/portal/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Assigned->value)
            ->assertJsonPath('data.driver_name', $this->driver->name);

        $this->actingAs($this->buyer)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->assertJsonPath('data.awaiting_confirmation', 0)
            ->assertJsonPath('data.scheduled', 1);
    });
});
