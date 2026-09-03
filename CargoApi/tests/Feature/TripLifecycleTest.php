<?php

declare(strict_types=1);

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * A trip is not just a row: dispatch and delivery records exist for its whole
 * life, and the module pages assume that. These pin the parts of the lifecycle
 * a client would otherwise have to stitch together itself.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
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
});

it('assigns the reference itself and opens a delivery log with the trip', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', $this->payload)
        ->assertCreated();

    $id = $response->json('data.id');

    expect($response->json('data.reference'))->toStartWith('CR-')
        ->and(DeliveryLog::where('trip_id', $id)->exists())->toBeTrue();
});

it('refuses a reference chosen by the client', function (): void {
    // `reference` is not in the rules, so it is stripped rather than honoured.
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', [...$this->payload, 'reference' => 'CR-00001'])
        ->assertCreated();

    expect($response->json('data.reference'))->not->toBe('CR-00001');
});

it('will not book the same person as driver and helper', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', [...$this->payload, 'helper_id' => $this->driver->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('helper_id');
});

it('will not accept an ETA before departure', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', [
            ...$this->payload,
            'eta' => now()->addHour()->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('eta');
});

it('writes a dispatch record when the unit goes out', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', $this->payload)->json('data.id');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/trips/{$id}/dispatch", ['location' => 'Manila depot · Bay 3'])
        ->assertOk()
        ->assertJsonPath('data.status', StatusValue::InTransit->value);

    $trip = Trip::with('dispatchRecord')->findOrFail($id);

    expect($trip->dispatchRecord)->not->toBeNull()
        ->and($trip->dispatchRecord->location)->toBe('Manila depot · Bay 3');
});

it('closes the trip, its dispatch record and its delivery log together', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', $this->payload)->json('data.id');
    $before = $this->driver->trips_completed;

    $this->actingAs($this->admin)
        ->postJson("/api/v1/trips/{$id}/dispatch", ['location' => 'Manila depot · Bay 3']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/trips/{$id}/complete", [
            'receiver_name' => 'L. Tan',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', StatusValue::Delivered->value);

    $trip = Trip::with(['dispatchRecord', 'deliveryLog', 'driver'])->findOrFail($id);

    expect($trip->deliveryLog->pod_ref)->toStartWith('POD-')
        ->and($trip->deliveryLog->delivered_at)->not->toBeNull()
        ->and($trip->dispatchRecord->arrived_at)->not->toBeNull()
        // Completing a trip is what credits the driver.
        ->and($trip->driver->trips_completed)->toBe($before + 1);
});

it('scopes the driver endpoints to whoever is calling', function (): void {
    $marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();

    $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        ...$this->payload,
        'status' => StatusValue::Assigned->value,
    ]);

    $this->actingAs($marco)->getJson('/api/v1/trips/pending')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // The administrator has no driver record, and saying so beats an empty
    // list that reads like an idle day.
    $this->actingAs($this->admin)->getJson('/api/v1/trips/pending')->assertNotFound();
});

describe('where a trip starts and ends', function (): void {
    it('books a trip with no coordinates at all', function (): void {
        // Booked over the phone against a town somebody knows. A form that
        // refuses this would be a worse form than the spreadsheet.
        $this->actingAs($this->admin)->postJson('/api/v1/trips', $this->payload)
            ->assertCreated()
            ->assertJsonPath('data.origin_lat', null)
            ->assertJsonPath('data.mapped', false);
    });

    it('stores the pin for each end', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            ...$this->payload,
            'origin' => 'Pagadian',
            'origin_lat' => 7.8257,
            'origin_lng' => 123.4370,
            'destination' => 'Ozamis',
            'destination_lat' => 8.1481,
            'destination_lng' => 123.8444,
        ])
            ->assertCreated()
            ->assertJsonPath('data.mapped', true)
            ->assertJsonPath('data.origin', 'Pagadian')
            ->assertJsonPath('data.destination', 'Ozamis');
    });

    it('refuses a latitude with no longitude', function (): void {
        // Half a coordinate is not a location.
        $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            ...$this->payload,
            'origin_lat' => 7.8257,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('origin_lng');
    });

    it('refuses a latitude off the earth', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            ...$this->payload,
            'origin_lat' => 91,
            'origin_lng' => 123.4370,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('origin_lat');
    });

    it('works out the distance from the two pins', function (): void {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            ...$this->payload,
            'origin_lat' => 7.8257,
            'origin_lng' => 123.4370,
            'destination_lat' => 8.1481,
            'destination_lng' => 123.8444,
        ])->assertCreated();

        // Pagadian to Ozamis is about 57 km as the crow flies. Checked as a
        // range, because asserting a haversine to the metre tests arithmetic
        // rather than behaviour.
        expect($response->json('data.distance_total_m'))
            ->toBeGreaterThan(50_000)
            ->toBeLessThan(65_000);
    });

    it('leaves a distance somebody entered alone', function (): void {
        // A dispatcher who knows the road distance has entered something
        // better than a straight line.
        $response = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            ...$this->payload,
            'distance_total_m' => 90_000,
            'origin_lat' => 7.8257,
            'origin_lng' => 123.4370,
            'destination_lat' => 8.1481,
            'destination_lng' => 123.8444,
        ])->assertCreated();

        expect($response->json('data.distance_total_m'))->toBe(90_000);
    });
});

describe('the driver hands the run over', function (): void {
    /** A run of Marco's, dispatched and on the road. */
    $onTheRoad = function (array $overrides = []) {
        $id = test()->actingAs(test()->admin)
            ->postJson('/api/v1/trips', [...test()->payload, ...$overrides])
            ->json('data.id');

        test()->actingAs(test()->admin)
            ->postJson("/api/v1/trips/{$id}/dispatch", ['location' => 'Manila depot · Bay 3']);

        return $id;
    };

    it('moves in transit to delivered against the proof', function () use ($onTheRoad): void {
        $id = $onTheRoad();
        $marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
        $before = $this->driver->trips_completed;

        $this->actingAs($marco)
            ->postJson('/api/v1/trips/current/deliver', [
                'receiver_name' => 'Ana Cruz',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Delivered->value);

        $trip = Trip::with(['deliveryLog', 'driver'])->findOrFail($id);

        expect($trip->deliveryLog->pod_ref)->toStartWith('POD-')
            ->and($trip->deliveryLog->receiver_name)->toBe('Ana Cruz')
            ->and($trip->deliveryLog->delivered_at)->not->toBeNull()
            ->and($trip->deliveryLog->status)->toBe(StatusValue::Delivered)
            ->and($trip->driver->trips_completed)->toBe($before + 1);
    });

    it('will not mark a delivery complete with no proof to show for it', function () use ($onTheRoad): void {
        $onTheRoad();
        $marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        $this->actingAs($marco)
            ->postJson('/api/v1/trips/current/deliver', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receiver_name');
    });

    it('refuses a hand-off for a run that never left the depot', function (): void {
        // Assigned, not dispatched. There is nothing on the road to deliver.
        $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            ...$this->payload,
            'status' => StatusValue::Assigned->value,
        ]);

        $marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        $this->actingAs($marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'Ana Cruz'])
            ->assertStatus(422);
    });

    it('will not credit the same run twice', function () use ($onTheRoad): void {
        $onTheRoad();
        $marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
        $before = $this->driver->trips_completed;

        $body = ['receiver_name' => 'Ana Cruz'];

        $this->actingAs($marco)->postJson('/api/v1/trips/current/deliver', $body)->assertOk();

        // The run is no longer in transit, so it is no longer current — the
        // second press of a button on a bad signal must not pay out again.
        $this->actingAs($marco)->postJson('/api/v1/trips/current/deliver', $body)->assertStatus(422);

        expect($this->driver->fresh()->trips_completed)->toBe($before + 1);
    });

    it('closes only the caller\'s own run', function () use ($onTheRoad): void {
        // Two runs on the road at once, one each. The endpoint carries no id,
        // so the only run Marco can close is his.
        $mine = $onTheRoad();
        $theirs = $onTheRoad(['driver_id' => $this->helper->id, 'helper_id' => $this->driver->id]);

        $marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        $this->actingAs($marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'Ana Cruz'])
            ->assertOk();

        expect(Trip::findOrFail($mine)->status)->toBe(StatusValue::Delivered)
            ->and(Trip::findOrFail($theirs)->status)->toBe(StatusValue::InTransit);
    });
});
