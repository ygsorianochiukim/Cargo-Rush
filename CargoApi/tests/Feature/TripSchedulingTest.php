<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * Who moves a trip along, and when.
 *
 * The office books work and nothing else: `scheduled` becomes due on a clock,
 * and the two statuses that describe what happened on the road belong to the
 * driver holding the handset. These pin that division, because it is a rule
 * about permission as much as about workflow — the kind that quietly erodes
 * the moment somebody adds a status dropdown to an admin form.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->helper = Driver::where('name', 'Jun Abad')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->payload = [
        'origin' => 'Manila',
        'destination' => 'Batangas',
        'cargo' => 'Dry goods, 12 pallets',
        'weight_kg' => 3200,
        'driver_id' => $this->driver->id,
        'helper_id' => $this->helper->id,
        'vehicle_id' => $this->vehicle->id,
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
    ];

    /** Book a trip as the office, in whatever state the test needs. */
    $this->book = function (array $overrides = []): string {
        return $this->actingAs($this->admin)
            ->postJson('/api/v1/trips', [...$this->payload, ...$overrides])
            ->json('data.id');
    };

    /** Work of Marco's that is confirmed and waiting to be started. */
    $this->waiting = fn (array $overrides = []): string => ($this->book)([
        'status' => StatusValue::Assigned->value,
        ...$overrides,
    ]);

    $this->alerts = fn (): int => NotificationItem::where('user_id', $this->driver->user_id)->count();
});

describe('scheduled work becomes due', function (): void {
    it('releases a scheduled trip once its time has passed, and tells the driver', function (): void {
        $id = ($this->book)([
            'status' => StatusValue::Scheduled->value,
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ]);

        expect(($this->alerts)())->toBe(0);

        $this->artisan('cargo:trips-release')->assertSuccessful();

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned)
            ->and(($this->alerts)())->toBe(1);
    });

    it('leaves work that is not due yet alone', function (): void {
        $id = ($this->book)([
            'status' => StatusValue::Scheduled->value,
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ]);

        $this->artisan('cargo:trips-release')->assertSuccessful();

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Scheduled)
            ->and(($this->alerts)())->toBe(0);
    });

    it('alerts once, however often the schedule runs', function (): void {
        ($this->book)([
            'status' => StatusValue::Scheduled->value,
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ]);

        // This runs every minute in production, so a repeat is the normal
        // case rather than an edge one: a released trip is no longer
        // scheduled, so the next sweep cannot find it and cannot alert again.
        $this->artisan('cargo:trips-release');
        $this->artisan('cargo:trips-release');
        $this->artisan('cargo:trips-release');

        expect(($this->alerts)())->toBe(1);
    });

    it('does not alert the whole fleet about an unassigned trip', function (): void {
        // A null user on a notification means fleet-wide, so a trip nobody is
        // booked on has to produce no row rather than one for everybody.
        ($this->book)([
            'driver_id' => null,
            'helper_id' => null,
            'status' => StatusValue::Scheduled->value,
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ]);

        $this->artisan('cargo:trips-release');

        expect(NotificationItem::whereNull('user_id')->count())->toBe(0);
    });
});

describe('the driver starts the run', function (): void {
    it('moves assigned to in transit and opens the dispatch record', function (): void {
        $id = ($this->waiting)();

        $this->actingAs($this->marco)
            ->postJson("/api/v1/trips/{$id}/start", ['location' => 'Manila depot · Bay 3'])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::InTransit->value);

        $trip = Trip::with('dispatchRecord')->findOrFail($id);

        expect($trip->dispatchRecord)->not->toBeNull()
            ->and($trip->dispatchRecord->location)->toBe('Manila depot · Bay 3')
            ->and($trip->dispatchRecord->dispatched_at)->not->toBeNull();
    });

    it('falls back to the booked pickup place when the handset has no fix', function (): void {
        $id = ($this->waiting)(['pickup_place' => 'Sta. Mesa yard']);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();

        expect(Trip::findOrFail($id)->dispatchRecord->location)->toBe('Sta. Mesa yard');
    });

    it('will not let a driver start a run that is not theirs', function (): void {
        // Booked to the helper, so it is not Marco's to leave on. The endpoint
        // names a trip, so this is the check that makes naming one safe.
        $id = ($this->waiting)([
            'driver_id' => $this->helper->id,
            'helper_id' => $this->driver->id,
        ]);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertForbidden();

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned);
    });

    it('will not start work that is still only scheduled', function (): void {
        // Not due yet. `cargo:trips-release` is what makes it startable, and
        // until it runs there is nothing for the driver to act on.
        $id = ($this->waiting)(['status' => StatusValue::Scheduled->value]);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertStatus(422);
    });

    it('will not start a request the office has not confirmed', function (): void {
        // `pending` is a request nobody has looked at — it may have no unit and
        // no agreed time. Letting a driver leave on one would mean whoever
        // pressed Start first got to skip the confirmation step.
        $id = ($this->waiting)(['status' => StatusValue::Pending->value]);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])
            ->assertStatus(422);

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Pending);
    });

    it('keeps an unconfirmed request out of the driver queue', function (): void {
        // The desk's queue, not the driver's. Showing it here would offer a
        // driver work they cannot start and were never promised.
        ($this->waiting)(['status' => StatusValue::Pending->value]);

        $this->actingAs($this->marco)->getJson('/api/v1/trips/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('will not put one driver on two runs at once', function (): void {
        $first = ($this->waiting)();
        $second = ($this->waiting)();

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$first}/start", [])->assertOk();
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$second}/start", [])->assertStatus(422);

        expect(Trip::findOrFail($second)->status)->toBe(StatusValue::Assigned);
    });

    it('runs the whole length of a trip from the handset', function (): void {
        // scheduled -> assigned -> in transit -> delivered, with the office
        // having done nothing but book it.
        $id = ($this->book)([
            'status' => StatusValue::Scheduled->value,
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ]);

        $this->artisan('cargo:trips-release');
        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::InTransit);

        $this->actingAs($this->marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'L. Tan'])
            ->assertOk();

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Delivered);
    });
});

describe('the office books, the driver reports', function (): void {
    it('refuses a trip the office marks in transit or delivered itself', function (): void {
        // Both statuses do more than set a column — dispatch records, delivery
        // logs, proof of delivery and driver credit hang off them — so neither
        // is a value the booking form gets to write.
        foreach ([StatusValue::InTransit, StatusValue::Delivered] as $status) {
            $this->actingAs($this->admin)
                ->postJson('/api/v1/trips', [...$this->payload, 'status' => $status->value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }
    });

    it('refuses to patch an existing trip into those either', function (): void {
        // The rule has to hold on update as well, or the form only has to be
        // saved twice to get around it.
        $id = ($this->book)();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Delivered->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('still lets the office book, assign, queue and cancel', function (): void {
        $statuses = [
            StatusValue::Scheduled,
            StatusValue::Assigned,
            StatusValue::Pending,
            StatusValue::Cancelled,
        ];

        foreach ($statuses as $status) {
            $this->actingAs($this->admin)
                ->postJson('/api/v1/trips', [...$this->payload, 'status' => $status->value])
                ->assertCreated()
                ->assertJsonPath('data.status', $status->value);
        }
    });
});
