<?php

declare(strict_types=1);

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * What a driver actually has at the door.
 *
 * The hand-off used to require a `pod_ref` — a reference number the driver had
 * no source for, and therefore invented in the cab. It looked like evidence and
 * was not: two runs could carry the same one, and a delivery could be closed
 * against a reference that named nothing.
 *
 * The reference is now the system's, assigned like a trip's `CR-` number. What
 * the driver is asked for is the photograph and the name of whoever took the
 * load — which is what they can actually see.
 *
 * The fake uploads here are built with `create(..., 'image/jpeg')` rather than
 * `image()`: the latter draws a real bitmap and needs the GD extension, which
 * is not a reasonable thing for this suite to require. The validation rules
 * read the mime type, so a declared one exercises them exactly the same way.
 */
beforeEach(function (): void {
    Storage::fake('public');

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    /** A run of Marco's, out on the road. */
    $this->onTheRoad = function (): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Manila',
            'destination' => 'Batangas',
            'cargo' => 'Dry goods, 12 pallets',
            'weight_kg' => 3200,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            'status' => StatusValue::Assigned->value,
        ])->json('data.id');

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();

        return $id;
    };
});

it('assigns the proof reference itself, in a running series', function (): void {
    $first = ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', ['receiver_name' => 'Ana Cruz'])
        ->assertOk();

    $second = ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', ['receiver_name' => 'L. Tan'])
        ->assertOk();

    $one = DeliveryLog::where('trip_id', $first)->firstOrFail()->pod_ref;
    $two = DeliveryLog::where('trip_id', $second)->firstOrFail()->pod_ref;

    expect($one)->toBe('POD-00001')
        ->and($two)->toBe('POD-00002')
        // Two runs sharing a reference is the failure the typed field allowed.
        ->and($one)->not->toBe($two);
});

it('ignores a reference the handset tries to choose', function (): void {
    ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', [
            'receiver_name' => 'Ana Cruz',
            'pod_ref' => 'POD-9999',
        ])
        ->assertOk();

    expect(DeliveryLog::firstOrFail()->pod_ref)->toBe('POD-00001');
});

it('gives no reference to a delivery that has not happened', function (): void {
    // The log is opened when the trip is booked. A POD number on an
    // undelivered run would read as proof of something that has not occurred.
    $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Manila',
        'destination' => 'Batangas',
        'cargo' => 'Dry goods',
        'weight_kg' => 3200,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $log = DeliveryLog::firstOrFail();

    expect($log->pod_ref)->toBeNull()
        ->and($log->status)->toBe(StatusValue::Pending);
});

it('keeps the photograph and hands back a URL to read it', function (): void {
    $id = ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', [
            'receiver_name' => 'Ana Cruz',
            'photo' => UploadedFile::fake()->create('at-the-door.jpg', 640, 'image/jpeg'),
        ])
        ->assertOk();

    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    expect($log->pod_image_path)->not->toBeNull();

    Storage::disk('public')->assertExists($log->pod_image_path);

    // The path is stored; the URL is derived, so moving the install does not
    // leave every past delivery pointing at a host that no longer exists.
    $this->actingAs($this->admin)->getJson("/api/v1/delivery-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.receiver_name', 'Ana Cruz')
        ->assertJsonPath('data.pod_image_url', $log->podImageUrl());
});

it('closes the run without a photograph when the gate has no signal', function (): void {
    // Optional on purpose. Refusing to close a run over a failed upload leaves
    // a driver standing at a door with nothing they can do.
    $id = ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', ['receiver_name' => 'Ana Cruz'])
        ->assertOk();

    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();

    expect($log->pod_image_path)->toBeNull()
        ->and($log->pod_ref)->toStartWith('POD-')
        ->and($log->status)->toBe(StatusValue::Delivered)
        ->and($log->podImageUrl())->toBeNull();
});

it('refuses a hand-off with nobody signing for it', function (): void {
    ($this->onTheRoad)();

    // The typed name is the signature. Without it the delivery is
    // unattributable, which is the gap the invented reference used to paper
    // over.
    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('receiver_name');
});

it('refuses something that is not a photograph', function (): void {
    ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', [
            'receiver_name' => 'Ana Cruz',
            'photo' => UploadedFile::fake()->create('manifest.pdf', 40, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo');
});

it('lets a late photograph attach without wiping the one already there', function (): void {
    $id = ($this->onTheRoad)();

    $this->actingAs($this->marco)
        ->post('/api/v1/trips/current/deliver', [
            'receiver_name' => 'Ana Cruz',
            'photo' => UploadedFile::fake()->create('first.jpg', 640, 'image/jpeg'),
        ])
        ->assertOk();

    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();
    $original = $log->pod_image_path;

    // Proof re-submitted with no file — a retry from a screen that lost its
    // attachment. It must not delete what is already filed.
    $this->actingAs($this->admin)
        ->post("/api/v1/delivery-logs/{$log->id}/proof", ['receiver_name' => 'Ana Cruz'])
        ->assertOk();

    expect($log->fresh()->pod_image_path)->toBe($original);
});

it('closes the run through the trip when proof arrives on an open log', function (): void {
    // The office completing a run on the driver's behalf. This route used to
    // set the trip's status itself, which skipped the dispatch record, the
    // driver credit, the day's income and the invoice — two paths to
    // `delivered` doing different amounts of work.
    $id = ($this->onTheRoad)();
    $log = DeliveryLog::where('trip_id', $id)->firstOrFail();
    $before = $this->driver->trips_completed;

    $this->actingAs($this->admin)
        ->post("/api/v1/delivery-logs/{$log->id}/proof", ['receiver_name' => 'Ana Cruz'])
        ->assertOk();

    $trip = $log->fresh()->trip;

    expect($trip->status)->toBe(StatusValue::Delivered)
        ->and($trip->billed_at)->not->toBeNull()
        ->and($trip->dispatchRecord->arrived_at)->not->toBeNull()
        ->and($this->driver->fresh()->trips_completed)->toBe($before + 1);
});
