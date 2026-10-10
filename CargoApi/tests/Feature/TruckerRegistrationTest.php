<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->signUp = fn (array $overrides = []) => $this->postJson('/api/v1/register/trucker', [
        'name' => 'Boyet Aquino',
        'contact_phone' => '0917 555 0444',
        'email' => 'boyet@example.ph',
        'password' => 'ten-wheeler-2026',
        'password_confirmation' => 'ten-wheeler-2026',
        'business_name' => 'Aquino Trucking Services',
        'licence_no' => 'N01-23-456789',
        'device_name' => 'pixel-8',
        ...$overrides,
    ]);
});

describe('signing up', function (): void {
    it('opens a login and a partner record, and no truck', function (): void {
        $response = ($this->signUp)()->assertCreated();

        $user = User::where('email', 'boyet@example.ph')->firstOrFail();

        expect($user->role)->toBe(Role::Trucker->value)
            ->and($user->company_id)->toBe($this->company->getKey());

        $trucker = Trucker::query()->firstWhere('user_id', $user->getKey());

        expect($trucker)->not->toBeNull()
            ->and($trucker->phone)->toBe('0917 555 0444')
            ->and($trucker->business_name)->toBe('Aquino Trucking Services')
            ->and($trucker->licence_no)->toBe('N01-23-456789');

        expect(TruckerVehicle::query()->where('trucker_id', $trucker->getKey())->count())->toBe(0);

        $response->assertJsonPath('meta.token_type', 'Bearer')
            ->assertJsonPath('meta.trucker_status', StatusValue::Pending->value);
    });

    it('lands pending, whatever the payload says', function (): void {
        ($this->signUp)(['status' => StatusValue::Active->value])->assertCreated();

        $trucker = Trucker::query()->firstWhere('licence_no', 'N01-23-456789');

        expect($trucker->status)->toBe(StatusValue::Pending)
            ->and($trucker->is_online)->toBeFalse()
            ->and($trucker->canTakeWork())->toBeFalse();
    });

    it('tells the desk somebody is waiting', function (): void {
        ($this->signUp)()->assertCreated();

        expect(NotificationItem::query()->where('title', 'New trucker registered')->exists())->toBeTrue();
    });

    it('refuses a second account on the same address', function (): void {
        ($this->signUp)()->assertCreated();

        ($this->signUp)(['licence_no' => 'N02-99-999999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    });

    it('refuses the same licence twice at the same fleet, in a sentence', function (): void {
        ($this->signUp)()->assertCreated();

        ($this->signUp)(['email' => 'boyet2@example.ph'])
            ->assertStatus(422);

        expect(Trucker::query()->where('licence_no', 'N01-23-456789')->count())->toBe(1);
    });

    it('lets the same person haul for a second fleet', function (): void {
        ($this->signUp)()->assertCreated();

        $rival = $this->makeCompany('Bay Coast Logistics');

        ($this->signUp)([
            'email' => 'boyet@baycoast.ph',
            'company_id' => $rival->getKey(),
        ])->assertCreated();

        expect(Trucker::withoutGlobalScopes()->where('licence_no', 'N01-23-456789')->count())->toBe(2);
    });

    it('never asks which fleet, and lands them on this one', function (): void {
        ($this->signUp)()->assertCreated();

        $user = User::where('email', 'boyet@example.ph')->firstOrFail();

        expect($user->company_id)->toBe($this->company->getKey());
        expect(Trucker::query()->firstWhere('user_id', $user->getKey())->company_id)
            ->toBe($this->company->getKey());
    });

    it('still honours a fleet named outright, for an install running two', function (): void {
        $rival = $this->makeCompany('Bay Coast Logistics');

        ($this->signUp)(['company_id' => $rival->getKey()])->assertCreated();

        expect(User::where('email', 'boyet@example.ph')->firstOrFail()->company_id)
            ->toBe($rival->getKey());
    });

    it('will not sign somebody up to a fleet that does not exist', function (): void {
        ($this->signUp)(['company_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])->assertNotFound();
    });

    it('will not sign somebody up to a suspended fleet', function (): void {
        $this->company->update(['status' => StatusValue::Inactive->value]);

        ($this->signUp)()->assertStatus(422);
    });

    it('asks for the name of the trucking service', function (): void {
        ($this->signUp)(['business_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('business_name');
    });

    it('takes an owner with no licence, and two of them do not clash', function (): void {
        ($this->signUp)(['licence_no' => ''])->assertCreated();
        ($this->signUp)(['licence_no' => null, 'email' => 'second@example.ph'])->assertCreated();

        expect(Trucker::query()->whereNull('licence_no')->count())->toBe(2);
    });

    it('refuses a licence that has already expired', function (): void {
        ($this->signUp)(['licence_expiry' => now()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('licence_expiry');
    });
});

describe('the waiting room', function (): void {
    beforeEach(function (): void {
        ($this->signUp)()->assertCreated();

        $this->truckerUser = User::where('email', 'boyet@example.ph')->firstOrFail();
        $this->trucker = Trucker::query()->firstWhere('user_id', $this->truckerUser->getKey());
    });

    it('answers an empty board rather than an error', function (): void {
        $this->actingAs($this->truckerUser)
            ->getJson('/api/v1/partner/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.can_take_work', false)
            ->assertJsonPath('meta.status', StatusValue::Pending->value);
    });

    it('will not let them go online', function (): void {
        $this->actingAs($this->truckerUser)
            ->postJson('/api/v1/partner/availability', ['is_online' => true])
            ->assertForbidden();

        expect($this->trucker->refresh()->is_online)->toBeFalse();
    });

    it('says so on GET /me, so the app opens on the right screen', function (): void {
        $this->actingAs($this->truckerUser)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', Role::Trucker->value)
            ->assertJsonPath('data.trucker_status', StatusValue::Pending->value)
            ->assertJsonPath('data.trucker_can_take_work', false)
            ->assertJsonPath('data.commission_bp', 1200);
    });

    it('cannot reach the office roster', function (): void {
        $this->actingAs($this->truckerUser)->getJson('/api/v1/truckers')->assertForbidden();
        $this->actingAs($this->truckerUser)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/approve")
            ->assertForbidden();
    });

    it('cannot see the trip board', function (): void {
        $this->actingAs($this->truckerUser)->getJson('/api/v1/trips')->assertForbidden();
    });
});

describe('the desk deciding', function (): void {
    beforeEach(function (): void {
        ($this->signUp)()->assertCreated();

        $this->truckerUser = User::where('email', 'boyet@example.ph')->firstOrFail();
        $this->trucker = Trucker::query()->firstWhere('user_id', $this->truckerUser->getKey());
    });

    it('approves, and the partner can then work', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Active->value);

        expect($this->trucker->refresh()->canTakeWork())->toBeFalse();

        Storage::fake(config('cargo.trucks.disk'));

        $truckId = $this->actingAs($this->truckerUser)
            ->post('/api/v1/partner/vehicles', [
                'plate' => 'ABC-1234', 'model' => 'Isuzu Forward', 'capacity_kg' => 12_000,
                ...collect(['front', 'left', 'right', 'back', 'plate'])
                    ->mapWithKeys(fn (string $slot): array => ["photo_{$slot}" => UploadedFile::fake()->image("{$slot}.jpg")])
                    ->all(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/vehicles/{$truckId}/verify")
            ->assertOk();

        $this->actingAs($this->truckerUser)
            ->postJson('/api/v1/partner/availability', ['is_online' => true])
            ->assertOk()
            ->assertJsonPath('data.can_take_work', true);
    });

    it('tells the partner they are in', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/approve")->assertOk();

        expect(NotificationItem::query()
            ->where('user_id', $this->truckerUser->getKey())
            ->where('title', 'You are approved')
            ->exists())->toBeTrue();
    });

    it('refuses to approve somebody twice', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/approve")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/approve")->assertStatus(422);
    });

    it('suspends without the partner being able to undo it', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/approve")->assertOk();
        $this->actingAs($this->truckerUser)->postJson('/api/v1/partner/availability', ['is_online' => true])->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/suspend", ['reason' => 'Licence under review'])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Inactive->value);

        expect($this->trucker->refresh()->canTakeWork())->toBeFalse();

        $this->actingAs($this->truckerUser)
            ->postJson('/api/v1/partner/availability', ['is_online' => true])
            ->assertForbidden();
    });

    it('has no rate of its own to set — the roster reads the fleet rate', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/rate", ['commission_bp' => 800])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['trucker_commission_bp' => 800])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/truckers/{$this->trucker->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_bp', 800);
    });

    it('counts the ones nobody has looked at, on the sidebar', function (): void {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/navigation')
            ->assertOk()
            ->assertJsonFragment(['key' => 'truckers', 'badge' => 1]);
    });
});

describe('tenancy', function (): void {
    it('keeps one fleet out of another fleet roster', function (): void {
        ($this->signUp)()->assertCreated();

        $rival = $this->makeCompany('Bay Coast Logistics');

        $rivalAdmin = $this->asCompany($rival, fn () => User::factory()->create([
            'role' => Role::Administrator->value,
            'company_id' => $rival->getKey(),
        ]));

        $this->actingAs($rivalAdmin)
            ->getJson('/api/v1/truckers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});
