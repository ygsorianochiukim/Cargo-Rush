<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ResetPasswordLink;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    Notification::fake();

    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->user = User::create([
        'name' => 'Ana Cruz',
        'email' => 'ana@haulage.test',
        'password' => 'OldPassw0rd!',
        'role' => 'administrator',
    ]);
});

describe('asking for a link', function (): void {
    it('sends one to an address that has an account', function (): void {
        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test'])
            ->assertOk()
            ->assertJsonPath('data.message', 'If that address has an account, a reset link is on its way to it.');

        Notification::assertSentTo($this->user, ResetPasswordLink::class);
    });

    it('says exactly the same thing for an address that does not', function (): void {
        $known = $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test']);
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@nowhere.test']);

        expect($unknown->status())->toBe($known->status())
            ->and($unknown->json())->toBe($known->json());

        Notification::assertCount(1);
    });

    it('points the link at the front end, carrying the token and the address', function (): void {
        config(['app.frontend_url' => 'https://app.cargorush.test']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test'])->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordLink::class, function ($notification): bool {
            $url = $notification->toMail($this->user)->actionUrl;

            return str_starts_with($url, 'https://app.cargorush.test/reset-password?token=')
                && str_contains($url, 'email=ana%40haulage.test');
        });
    });

    it('is metered, so it cannot be used to mail somebody a hundred times', function (): void {
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test']);
        }

        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test'])
            ->assertStatus(429);
    });
});

describe('using the link', function (): void {
    function tokenFor(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    it('sets the new password and lets them straight back in', function (): void {
        $this->postJson('/api/v1/reset-password', [
            'token' => tokenFor($this->user),
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'device_name' => 'test',
        ])->assertCreated();
    });

    it('revokes every device token, because that is usually the point', function (): void {
        $token = $this->user->createToken('their-phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/reset-password', [
            'token' => tokenFor($this->user),
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('refuses a token that has already been used', function (): void {
        $token = tokenFor($this->user);

        $reset = fn () => $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ]);

        $reset()->assertOk();
        $reset()->assertStatus(422)->assertJsonValidationErrors('token');
    });

    it('blames the link rather than the password', function (): void {
        $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'This reset link is no longer valid. Ask for a new one.');
    });

    it('holds a reset password to the same strength registration asks for', function (): void {
        $this->postJson('/api/v1/reset-password', [
            'token' => tokenFor($this->user),
            'email' => $this->user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });
});

describe('changing it while signed in', function (): void {
    it('changes the password when the current one is given', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/me/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertNoContent();

        $this->postJson('/api/v1/login', [
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'device_name' => 'test',
        ])->assertCreated();
    });

    it('refuses without the current password', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/me/password', [
            'current_password' => 'guessing',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'That is not your current password.');
    });

    it('leaves other devices signed in', function (): void {
        $token = $this->user->createToken('their-phone')->plainTextToken;

        $this->actingAs($this->user)->postJson('/api/v1/me/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertNoContent();

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    });

    it('turns away an unauthenticated caller', function (): void {
        $this->postJson('/api/v1/me/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertUnauthorized();
    });
});
