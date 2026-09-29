<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\TruckVerification;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Repositories\TruckerRepository;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A trucker's truck is photographed on the way in and checked by Cargo Rush
 * before it can take a load.
 *
 * Front, left, right, back and the plate are required; the engine bay is
 * optional. Until the office verifies it the truck is on the books but off the
 * road, whatever the trucker's own switch says.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    Storage::fake(config('cargo.trucks.disk'));

    $this->user = User::factory()->create([
        'role' => Role::Trucker->value,
        'company_id' => $this->company->getKey(),
    ]);
    $this->trucker = Trucker::factory()->create([
        'user_id' => $this->user->getKey(),
        'status' => StatusValue::Active->value,
        'is_online' => true,
    ]);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->send = function (array $photos, array $fields = []) {
        return $this->actingAs($this->user)->post('/api/v1/partner/vehicles', [
            'plate' => 'KAB-2231',
            'model' => 'Hino 500',
            'capacity_kg' => 8000,
            ...$fields,
            ...collect($photos)
                ->mapWithKeys(fn (string $slot): array => ["photo_{$slot}" => UploadedFile::fake()->image("{$slot}.jpg")])
                ->all(),
        ], ['Accept' => 'application/json']);
    };
});

const EVERY_REQUIRED = ['front', 'left', 'right', 'back', 'plate'];

it('takes the four sides and the plate, and keeps each photograph', function (): void {
    $response = ($this->send)([...EVERY_REQUIRED, 'engine'])
        ->assertCreated()
        ->assertJsonPath('data.verification', TruckVerification::Pending->value);

    foreach ([...EVERY_REQUIRED, 'engine'] as $slot) {
        expect($response->json("data.photos.{$slot}"))->toBeString();
    }

    $truck = TruckerVehicle::query()->findOrFail($response->json('data.id'));

    foreach ([...EVERY_REQUIRED, 'engine'] as $slot) {
        Storage::disk(config('cargo.trucks.disk'))->assertExists($truck->getAttribute("photo_{$slot}_path"));
    }
});

it('does not need the engine', function (): void {
    ($this->send)(EVERY_REQUIRED)
        ->assertCreated()
        ->assertJsonPath('data.photos.engine', null);
});

it('refuses a truck missing any of the required photographs', function (string $missing): void {
    ($this->send)(array_values(array_diff(EVERY_REQUIRED, [$missing])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors("photo_{$missing}");

    expect(TruckerVehicle::query()->count())->toBe(0);
})->with(EVERY_REQUIRED);

it('refuses a file that is not a photograph', function (): void {
    $this->actingAs($this->user)->post('/api/v1/partner/vehicles', [
        'plate' => 'KAB-1', 'model' => 'Hino', 'capacity_kg' => 8000,
        ...collect(EVERY_REQUIRED)->mapWithKeys(fn ($s) => ["photo_{$s}" => UploadedFile::fake()->image("{$s}.jpg")])->all(),
        'photo_plate' => UploadedFile::fake()->create('plate.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photo_plate');
});

it('keeps an unchecked truck off the road until the office verifies it', function (): void {
    $id = ($this->send)(EVERY_REQUIRED)->assertCreated()->json('data.id');

    expect($this->trucker->refresh()->load('vehicles')->canTakeWork())->toBeFalse();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/truckers/{$this->trucker->id}/vehicles/{$id}/verify")
        ->assertOk()
        ->assertJsonPath('data.verification', TruckVerification::Verified->value);

    expect($this->trucker->refresh()->load('vehicles')->canTakeWork())->toBeTrue()
        ->and(TruckerVehicle::query()->findOrFail($id)->verified_by)->toBe($this->admin->getKey())
        ->and(NotificationItem::query()
            ->where('user_id', $this->user->getKey())
            ->where('title', 'KAB-2231 is verified')
            ->exists())->toBeTrue();
});

it('turns a truck down with a reason, and new photos send it back to be checked', function (): void {
    $id = ($this->send)(EVERY_REQUIRED)->assertCreated()->json('data.id');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/truckers/{$this->trucker->id}/vehicles/{$id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/truckers/{$this->trucker->id}/vehicles/{$id}/reject", ['reason' => 'The plate is blurred.'])
        ->assertOk()
        ->assertJsonPath('data.verification', TruckVerification::Rejected->value)
        ->assertJsonPath('data.rejection_reason', 'The plate is blurred.');

    expect(NotificationItem::query()
        ->where('user_id', $this->user->getKey())
        ->where('title', 'KAB-2231 was not verified')
        ->exists())->toBeTrue();

    $old = TruckerVehicle::query()->findOrFail($id)->photo_plate_path;

    $this->actingAs($this->user)
        ->post("/api/v1/partner/vehicles/{$id}/photos", [
            'photo_plate' => UploadedFile::fake()->image('plate-again.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.verification', TruckVerification::Pending->value)
        ->assertJsonPath('data.rejection_reason', null);

    $truck = TruckerVehicle::query()->findOrFail($id);

    expect($truck->photo_plate_path)->not->toBe($old);
    Storage::disk(config('cargo.trucks.disk'))->assertMissing($old);
    Storage::disk(config('cargo.trucks.disk'))->assertExists($truck->photo_front_path);
});

it('asks for at least one photo when re-sending', function (): void {
    $id = ($this->send)(EVERY_REQUIRED)->assertCreated()->json('data.id');

    $this->actingAs($this->user)
        ->post("/api/v1/partner/vehicles/{$id}/photos", [], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

it('sends a checked truck back for checking when its plate changes', function (): void {
    $truck = TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->getKey()]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/partner/vehicles/{$truck->id}", ['status' => 'maintenance'])
        ->assertOk()
        ->assertJsonPath('data.verification', TruckVerification::Verified->value);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/partner/vehicles/{$truck->id}", ['plate' => 'NEW-0001'])
        ->assertOk()
        ->assertJsonPath('data.verification', TruckVerification::Pending->value);
});

it('counts trucks waiting to be checked on the office badge', function (): void {
    TruckerVehicle::factory()->awaitingCheck()->count(2)->create(['trucker_id' => $this->trucker->getKey()]);

    expect(app(TruckerRepository::class)->pendingCount())->toBe(2);
});

it('treats a truck the office adds as already checked, with no photos needed', function (): void {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/truckers/{$this->trucker->id}/vehicles", [
            'plate' => 'DESK-001', 'model' => 'Fuso', 'capacity_kg' => 6000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.verification', TruckVerification::Verified->value);
});

it('will not let a trucker verify their own truck', function (): void {
    $truck = TruckerVehicle::factory()->awaitingCheck()->create(['trucker_id' => $this->trucker->getKey()]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/truckers/{$this->trucker->id}/vehicles/{$truck->id}/verify")
        ->assertForbidden();

    expect($truck->refresh()->verification)->toBe(TruckVerification::Pending);
});

it('will not let a trucker hand a run to an unchecked truck', function (): void {
    $truck = TruckerVehicle::factory()->awaitingCheck()->create(['trucker_id' => $this->trucker->getKey()]);

    expect($truck->canCarry(100, null))->toBeFalse()
        ->and($this->trucker->refresh()->load('vehicles')->activeVehicle())->toBeNull();
});
