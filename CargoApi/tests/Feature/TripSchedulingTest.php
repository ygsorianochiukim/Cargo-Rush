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

beforeEach(function (): void {
    zoneCard();

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
        'helper_ids' => [$this->helper->id],
        'vehicle_id' => $this->vehicle->id,
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
    ];

    $this->book = function (array $overrides = []): string {
        return $this->actingAs($this->admin)
            ->postJson('/api/v1/trips', [...$this->payload, ...$overrides])
            ->json('data.id');
    };

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

        $this->artisan('cargo:trips-release');
        $this->artisan('cargo:trips-release');
        $this->artisan('cargo:trips-release');

        expect(($this->alerts)())->toBe(1);
    });

    it('does not alert the whole fleet about an unassigned trip', function (): void {
        ($this->book)([
            'driver_id' => null,
            'helper_ids' => [],
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
        $this->passPreTripCheck($id);

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
        $this->passPreTripCheck($id);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();

        expect(Trip::findOrFail($id)->dispatchRecord->location)->toBe('Sta. Mesa yard');
    });

    it('will not let a driver start a run that is not theirs', function (): void {
        $id = ($this->waiting)([
            'driver_id' => $this->helper->id,
            'helper_ids' => [$this->driver->id],
        ]);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertForbidden();

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned);
    });

    it('will not start work that is still only scheduled', function (): void {
        $id = ($this->waiting)(['status' => StatusValue::Scheduled->value]);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertStatus(422);
    });

    it('will not start a request the office has not confirmed', function (): void {
        $id = ($this->waiting)(['status' => StatusValue::Pending->value]);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])
            ->assertStatus(422);

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Pending);
    });

    it('keeps an unconfirmed request out of the driver queue', function (): void {
        ($this->waiting)(['status' => StatusValue::Pending->value]);

        $this->actingAs($this->marco)->getJson('/api/v1/trips/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('will not put one driver on two runs at once', function (): void {
        $first = ($this->waiting)();
        $second = ($this->waiting)();
        $this->passPreTripCheck($first);
        $this->passPreTripCheck($second);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$first}/start", [])->assertOk();
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$second}/start", [])->assertStatus(422);

        expect(Trip::findOrFail($second)->status)->toBe(StatusValue::Assigned);
    });

    it('runs the whole length of a trip from the handset', function (): void {
        $id = ($this->book)([
            'status' => StatusValue::Scheduled->value,
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ]);

        $this->artisan('cargo:trips-release');
        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned);

        $this->passPreTripCheck($id);

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
        foreach ([StatusValue::InTransit, StatusValue::Delivered] as $status) {
            $this->actingAs($this->admin)
                ->postJson('/api/v1/trips', [...$this->payload, 'status' => $status->value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }
    });

    it('refuses to patch an existing trip into those either', function (): void {
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
