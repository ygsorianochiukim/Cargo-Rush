<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The photograph that could not go up at the gate.
 *
 * A run is closed with a typed name and, where the signal allows, a picture.
 * Where it does not, the run still closes — and the picture has to be sendable
 * afterwards, from the phone, by whoever handed the load over and nobody else.
 */
beforeEach(function (): void {
    Storage::fake('public');

    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->photo = fn () => UploadedFile::fake()->create('pod.jpg', 400, 'image/jpeg');

    /** One of Marco's runs, delivered with no photograph. */
    $this->deliveredBare = function (): DeliveryLog {
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
        $this->actingAs($this->marco)
            ->post('/api/v1/trips/current/deliver', ['receiver_name' => 'Ana Cruz'])
            ->assertOk();

        return DeliveryLog::where('trip_id', $id)->firstOrFail();
    };
});

describe('a driver', function (): void {
    it('can send the photo for their own delivered run afterwards', function (): void {
        $log = ($this->deliveredBare)();
        expect($log->pod_image_path)->toBeNull();

        $this->actingAs($this->marco)
            ->post("/api/v1/delivery-logs/{$log->id}/proof", [
                'receiver_name' => 'Ana Cruz',
                'photo' => ($this->photo)(),
            ])
            ->assertOk()
            ->assertJsonPath('data.pod_image_url', fn ($url) => is_string($url) && $url !== '');

        expect($log->fresh()->pod_image_path)->not->toBeNull();
    });

    it('cannot send one for somebody else’s run', function (): void {
        $log = ($this->deliveredBare)();

        $jun = User::factory()->create(['role' => Role::Driver->value, 'company_id' => $this->company->getKey()]);
        Driver::create([
            'user_id' => $jun->getKey(), 'name' => 'Jun Dela Cruz',
            'licence_no' => 'N03-22-000111', 'licence_expiry' => '2028-01-01', 'status' => 'active',
        ]);

        $this->actingAs($jun)
            ->post("/api/v1/delivery-logs/{$log->id}/proof", [
                'receiver_name' => 'Someone',
                'photo' => ($this->photo)(),
            ])
            ->assertNotFound();

        expect($log->fresh()->pod_image_path)->toBeNull();
    });

    it('leaves the office able to attach one for any run', function (): void {
        $log = ($this->deliveredBare)();

        $this->actingAs($this->admin)
            ->post("/api/v1/delivery-logs/{$log->id}/proof", [
                'receiver_name' => 'Ana Cruz',
                'photo' => ($this->photo)(),
            ])
            ->assertOk();
    });
});

describe('a partner trucker', function (): void {
    beforeEach(function (): void {
        $partner = function (string $name): array {
            $user = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
            $trucker = Trucker::factory()->approved()->create(['user_id' => $user->getKey(), 'name' => $name]);
            TruckerVehicle::factory()->carrying(15_000)->create(['trucker_id' => $trucker->getKey()]);

            return [$user, $trucker];
        };

        [$this->user, $this->trucker] = $partner('Boyet');
        [$this->other] = $partner('Liza');

        $this->trip = Trip::create([
            'customer_id' => Customer::query()->firstOrFail()->getKey(),
            'trucker_id' => $this->trucker->getKey(),
            'origin' => 'Iponan',
            'destination' => 'Bukidnon',
            'cargo' => 'Rice, 200 sacks',
            'weight_kg' => 10_000,
            'status' => StatusValue::Pending->value,
            'scheduled_at' => now()->addDay(),
            'price_cents' => 1_000_000,
            'currency' => 'PHP',
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/partner/jobs/{$this->trip->id}/accept")->assertCreated();
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$this->trip->id}/start")->assertOk();
    });

    it('can send the photo after handing over, and sees that it arrived', function (): void {
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/trips/history')
            ->assertJsonPath('data.0.has_pod_photo', false);

        $this->actingAs($this->user)
            ->post("/api/v1/partner/trips/{$this->trip->id}/proof", [
                'receiver_name' => 'Mrs Uy',
                'photo' => ($this->photo)(),
            ])
            ->assertOk()
            ->assertJsonPath('data.has_pod_photo', true);

        expect($this->trip->deliveryLog->fresh()->pod_image_path)->not->toBeNull();
    });

    it('cannot send one for a run still on the road — that is the hand-off', function (): void {
        $this->actingAs($this->user)
            ->post("/api/v1/partner/trips/{$this->trip->id}/proof", [
                'receiver_name' => 'Mrs Uy',
                'photo' => ($this->photo)(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        expect($this->trip->fresh()->status)->toBe(StatusValue::InTransit);
    });

    it('cannot send one for another partner’s run', function (): void {
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $this->actingAs($this->other)
            ->post("/api/v1/partner/trips/{$this->trip->id}/proof", [
                'receiver_name' => 'Nobody',
                'photo' => ($this->photo)(),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();

        // Nor through the delivery-log route, which is the other door in.
        $this->actingAs($this->other)
            ->post("/api/v1/delivery-logs/{$this->trip->deliveryLog->id}/proof", [
                'receiver_name' => 'Nobody',
                'photo' => ($this->photo)(),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();
    });
});
