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

beforeEach(function (): void {
    zoneCard();

    Storage::fake('public');

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

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

        $this->passPreTripCheck($id);
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

    $this->actingAs($this->admin)->getJson("/api/v1/delivery-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.receiver_name', 'Ana Cruz')
        ->assertJsonPath('data.pod_image_url', $log->podImageUrl());
});

it('closes the run without a photograph when the gate has no signal', function (): void {
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

    $this->actingAs($this->admin)
        ->post("/api/v1/delivery-logs/{$log->id}/proof", ['receiver_name' => 'Ana Cruz'])
        ->assertOk();

    expect($log->fresh()->pod_image_path)->toBe($original);
});

it('closes the run through the trip when proof arrives on an open log', function (): void {
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
