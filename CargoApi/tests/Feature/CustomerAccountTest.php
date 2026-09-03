<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * The login a customer gets when the office adds them.
 *
 * Adding a firm used to produce a row and nothing else: the customer existed
 * on the books and had no way in, so a delivery request still arrived as a
 * phone call and somebody had to be asked to run `cargo:user` before the
 * portal was any use. What these pin is that the account is created with the
 * record, that the starting password is said once and never again, and that an
 * account which already exists is left alone — a customer who has changed
 * their password does not lose it because the desk corrected a rating.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(UserSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->password = (string) config('cargo.portal.default_password');

    $this->add = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/customers', [
            'name' => 'Southline Trading',
            'contact' => 'ops@southline.ph',
            ...$overrides,
        ]);
});

describe('adding a customer', function (): void {
    it('creates a login for the firm with the default password', function (): void {
        $response = ($this->add)(['email' => 'desk@southline.ph'])->assertCreated();

        $firm = Customer::findOrFail($response->json('data.id'));
        $login = $firm->logins()->firstOrFail();

        expect($login->email)->toBe('desk@southline.ph')
            ->and($login->role)->toBe(Role::Customer->value)
            ->and($login->name)->toBe('Southline Trading')
            ->and(Hash::check($this->password, $login->password))->toBeTrue();
    });

    it('says the starting password once, in the reply to the form', function (): void {
        // The office has one chance to read it and pass it on: it is not a
        // field of a customer record, so no read prints it again.
        $created = ($this->add)(['email' => 'desk@southline.ph'])->assertCreated();

        expect($created->json('data.default_password'))->toBe($this->password)
            ->and($created->json('data.login_email'))->toBe('desk@southline.ph');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/customers/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.default_password', null)
            ->assertJsonPath('data.login_email', 'desk@southline.ph');
    });

    it('lets the firm sign in and open their portal straight away', function (): void {
        ($this->add)(['email' => 'desk@southline.ph'])->assertCreated();

        $this->postJson('/api/v1/login', [
            'email' => 'desk@southline.ph',
            'password' => $this->password,
            'device_name' => 'pixel-8',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', Role::Customer->value);

        // The point of the account rather than the fact of it: the firm can
        // read its own board without anybody at the desk doing anything.
        $this->actingAs(User::where('email', 'desk@southline.ph')->firstOrFail())
            ->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Southline Trading');
    });

    it('signs them in on the contact when the contact is an address', function (): void {
        // The common case: `ops@southline.ph` is already both the address the
        // office writes to and the one the firm would sign in with.
        $created = ($this->add)()->assertCreated();

        expect($created->json('data.login_email'))->toBe('ops@southline.ph')
            ->and($created->json('data.default_password'))->toBe($this->password);
    });

    it('makes no account for a firm the desk only ever rings', function (): void {
        $created = ($this->add)(['contact' => '0917 555 0134'])->assertCreated();

        expect($created->json('data.login_email'))->toBeNull()
            ->and($created->json('data.default_password'))->toBeNull()
            ->and(Customer::findOrFail($created->json('data.id'))->logins()->count())->toBe(0);
    });

    it('leaves an existing account alone when the contact happens to be it', function (): void {
        // The fallback does not go through the form rule, so it has to refuse
        // here too: taking over the accountant's login — or attaching a firm
        // to it — is far worse than a customer without one. The firm is still
        // added, because that is what the desk asked for.
        $created = ($this->add)(['contact' => 'accounts@cargorush.ph'])->assertCreated();

        expect($created->json('data.login_email'))->toBeNull()
            ->and($created->json('data.default_password'))->toBeNull()
            ->and(User::where('email', 'accounts@cargorush.ph')->firstOrFail()->customer_id)->toBeNull();
    });

    it('turns away an address that is already an account', function (): void {
        // Attaching a second firm to an existing login would let one customer
        // read another firm's deliveries, so this is a 422 and not a silent
        // skip.
        ($this->add)(['email' => 'admin@cargorush.ph'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        expect(Customer::where('name', 'Southline Trading')->exists())->toBeFalse();
    });
});

describe('editing a customer', function (): void {
    it('grants a login to a firm that never had one', function (): void {
        // How the customers already on the books get theirs: every one of them
        // predates the account being created with the record.
        $firm = Customer::create([
            'name' => 'Highland Retail',
            'contact' => '0917 555 0134',
            'status' => 'active',
        ]);

        $updated = $this->actingAs($this->admin)
            ->patchJson('/api/v1/customers/'.$firm->id, ['email' => 'admin@highland.ph'])
            ->assertOk();

        expect($updated->json('data.login_email'))->toBe('admin@highland.ph')
            ->and($updated->json('data.default_password'))->toBe($this->password);
    });

    it('does not mint an account nobody asked for', function (): void {
        // The asymmetry with create, on purpose: there the contact stands in
        // for a login address, here it does not. Correcting a rating must not
        // quietly produce credentials for an address the desk never named.
        $firm = Customer::create([
            'name' => 'Metro Grocers',
            'contact' => 'supply@metrogrocers.ph',
            'status' => 'active',
        ]);

        $edited = $this->actingAs($this->admin)
            ->patchJson('/api/v1/customers/'.$firm->id, ['rating' => 4.9])
            ->assertOk();

        expect($edited->json('data.login_email'))->toBeNull()
            ->and($edited->json('data.default_password'))->toBeNull()
            ->and($firm->logins()->count())->toBe(0);
    });

    it('never resets a password the customer may have changed', function (): void {
        $id = ($this->add)(['email' => 'desk@southline.ph'])->json('data.id');

        $login = User::where('email', 'desk@southline.ph')->firstOrFail();
        $login->update(['password' => Hash::make('their-own-choice')]);

        $edited = $this->actingAs($this->admin)
            ->patchJson('/api/v1/customers/'.$id, ['rating' => 4.6])
            ->assertOk();

        expect($edited->json('data.default_password'))->toBeNull()
            ->and(Hash::check('their-own-choice', $login->refresh()->password))->toBeTrue()
            // One firm, one account — the edit did not add a second.
            ->and(Customer::findOrFail($id)->logins()->count())->toBe(1);
    });

    it('accepts the address the firm already signs in with', function (): void {
        // Resending the record unchanged is what saving the form again does;
        // the firm's own login must not read as somebody else's account.
        $id = ($this->add)(['email' => 'desk@southline.ph'])->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/customers/'.$id, [
                'name' => 'Southline Trading Corp.',
                'email' => 'desk@southline.ph',
            ])
            ->assertOk()
            ->assertJsonPath('data.login_email', 'desk@southline.ph');
    });
});
