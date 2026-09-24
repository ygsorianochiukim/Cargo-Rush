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

/**
 * An owner-operator signing themselves up, and the waiting room they land in.
 *
 * The third public registration, and the one with the most at stake. A bad
 * shipper sign-up wastes a desk's afternoon; a bad trucker sign-up is a
 * stranger driving away with somebody's cargo. So the whole of this feature
 * turns on one column — `truckers.status` — and these tests hold the line it
 * draws from both sides: a registration is real and usable the moment it is
 * approved, and reaches nothing at all until it is.
 */
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
        // No fleet. A trucker registers with the platform's own, wherever in
        // the country they are — see `TruckerRegistrationService::carrier()`.
        'licence_no' => 'N01-23-456789',
        'plate' => 'ABC-1234',
        'model' => 'Isuzu Forward',
        'capacity_kg' => 12_000,
        'device_name' => 'pixel-8',
        ...$overrides,
    ]);
});

describe('signing up', function (): void {
    it('opens a login, a partner record and a truck in one act', function (): void {
        $response = ($this->signUp)()->assertCreated();

        $user = User::where('email', 'boyet@example.ph')->firstOrFail();

        expect($user->role)->toBe(Role::Trucker->value)
            // Belongs to the fleet they chose from the first second — unlike a
            // shipper, who belongs to nobody until their first request.
            ->and($user->company_id)->toBe($this->company->getKey());

        $trucker = Trucker::query()->firstWhere('user_id', $user->getKey());

        expect($trucker)->not->toBeNull()
            ->and($trucker->phone)->toBe('0917 555 0444')
            ->and($trucker->licence_no)->toBe('N01-23-456789');

        // The truck is not a second step. A partner with no unit would sit on
        // the board accepting work with nothing to haul it in.
        expect(TruckerVehicle::query()->where('trucker_id', $trucker->getKey())->count())->toBe(1);

        // Answers signed in, in the shape a login does, so the app carries on
        // through one code path rather than two.
        $response->assertJsonPath('meta.token_type', 'Bearer')
            ->assertJsonPath('meta.trucker_status', StatusValue::Pending->value);
    });

    it('lands pending, whatever the payload says', function (): void {
        // The one thing a registration must not be able to do is vet itself.
        ($this->signUp)(['status' => StatusValue::Active->value])->assertCreated();

        $trucker = Trucker::query()->firstWhere('licence_no', 'N01-23-456789');

        expect($trucker->status)->toBe(StatusValue::Pending)
            ->and($trucker->is_online)->toBeFalse()
            ->and($trucker->canTakeWork())->toBeFalse();
    });

    it('tells the desk somebody is waiting', function (): void {
        ($this->signUp)()->assertCreated();

        // Not a courtesy: the row sits `pending` until a human acts, so a
        // notification nobody sends is a partner watching an empty board
        // indefinitely.
        expect(NotificationItem::query()->where('title', 'New trucker registered')->exists())->toBeTrue();
    });

    it('refuses a second account on the same address', function (): void {
        ($this->signUp)()->assertCreated();

        ($this->signUp)(['licence_no' => 'N02-99-999999', 'plate' => 'XYZ-9999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    });

    it('refuses the same licence twice at the same fleet, in a sentence', function (): void {
        ($this->signUp)()->assertCreated();

        // A licence is one person, and one person is one partner per haulier.
        // The unique index guarantees it; this asserts somebody registering for
        // the second time is told why rather than handed a 500.
        ($this->signUp)(['email' => 'boyet2@example.ph', 'plate' => 'XYZ-9999'])
            ->assertStatus(422);

        expect(Trucker::query()->where('licence_no', 'N01-23-456789')->count())->toBe(1);
    });

    it('lets the same person haul for a second fleet', function (): void {
        ($this->signUp)()->assertCreated();

        $rival = $this->makeCompany('Bay Coast Logistics');

        // The licence index is per company on purpose: a man on one haulier's
        // books and partnered with another is ordinary, and a system-wide index
        // would refuse it with a message about a duplicate.
        ($this->signUp)([
            'email' => 'boyet@baycoast.ph',
            'company_id' => $rival->getKey(),
        ])->assertCreated();

        expect(Trucker::withoutGlobalScopes()->where('licence_no', 'N01-23-456789')->count())->toBe(2);
    });

    it('never asks which fleet, and lands them on this one', function (): void {
        // The form used to offer the public carrier directory, and it was the
        // wrong question twice over: it asked somebody with a truck to pick a
        // haulier before they had any basis to, and it implied a choice the
        // business does not offer. Choosing happens on the customer's side,
        // per load.
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

    it('asks for the licence and the truck, because the fleet relies on both', function (): void {
        ($this->signUp)([
            'licence_no' => '',
            'plate' => '',
            'capacity_kg' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['licence_no', 'plate', 'capacity_kg']);
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
        // An empty list *with a reason*. A 403 here would read as a broken app;
        // the meta is what lets the screen say "we are checking your licence".
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
            // The rate is stated up front, so the board can quote a take
            // without a second call.
            ->assertJsonPath('data.commission_bp', 1200);
    });

    it('cannot reach the office roster', function (): void {
        // A partner is not staff. `truckers.view` is the desk's permission and
        // the trucker role holds none of it.
        $this->actingAs($this->truckerUser)->getJson('/api/v1/truckers')->assertForbidden();
        $this->actingAs($this->truckerUser)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/approve")
            ->assertForbidden();
    });

    it('cannot see the trip board', function (): void {
        // The whole board, as against their own work. A partner holds no
        // `trips.view`, and that is what keeps one forgotten `where` from
        // showing them every load in the company.
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

        // Approved but not yet online — the two flags are separate, and the
        // office's decision does not flip the partner's own switch.
        expect($this->trucker->refresh()->canTakeWork())->toBeFalse();

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

        // The switch is deliberately left alone — suspending sets the standing,
        // and flipping `is_online` here would simply be undone next time they
        // opened the app. What stops them is `canTakeWork()` needing both.
        expect($this->trucker->refresh()->canTakeWork())->toBeFalse();

        $this->actingAs($this->truckerUser)
            ->postJson('/api/v1/partner/availability', ['is_online' => true])
            ->assertForbidden();
    });

    it('has no rate of its own to set — the roster reads the fleet rate', function (): void {
        // There was an endpoint here, and a number field on the partner's
        // detail screen behind it. Both are gone: the commission is one
        // standing term for everybody the firm hauls with, set on the settings
        // card with the tariff and the tax rates. What the roster shows is
        // that rate, and moving it moves what every partner reads.
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

        // The partner exists, and belongs to somebody else. A roster that
        // answered with them would be the one failure the whole tenancy design
        // exists to prevent.
        $this->actingAs($rivalAdmin)
            ->getJson('/api/v1/truckers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});
