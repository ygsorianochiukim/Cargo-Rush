<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\Demo\OperationsSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The contract both clients are written against — DESIGN.md section 7.
 *
 * These are not tests of any one module. They are tests that the rules the
 * clients rely on hold everywhere: the envelope, the status vocabulary, the
 * money format, and the fact that the shell is data-driven.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(OperationsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->driver = User::where('email', 'marco@cargorush.ph')->firstOrFail();
});

describe('authentication', function (): void {
    it('turns anyone away without credentials', function (): void {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('returns a bearer token when a device asks for one', function (): void {
        $this->postJson('/api/v1/login', [
            'email' => 'marco@cargorush.ph',
            'password' => 'password',
            'device_name' => 'pixel-8',
        ])
            ->assertCreated()
            // The token rides in `meta` so the shape of `data` does not change
            // with the auth style.
            ->assertJsonPath('data.role', 'driver')
            ->assertJsonPath('meta.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['id', 'name', 'role', 'role_label'], 'meta' => ['token']]);
    });

    it('says the same thing for a bad password as for an unknown address', function (): void {
        $wrongPassword = $this->postJson('/api/v1/login', [
            'email' => 'marco@cargorush.ph',
            'password' => 'nope',
        ])->assertStatus(422);

        $noSuchUser = $this->postJson('/api/v1/login', [
            'email' => 'ghost@cargorush.ph',
            'password' => 'nope',
        ])->assertStatus(422);

        expect($wrongPassword->json('errors.email'))->toBe($noSuchUser->json('errors.email'));
    });
});

describe('the identity endpoint', function (): void {
    it('separates the machine role from the display label', function (): void {
        $this->actingAs($this->admin)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'administrator')
            ->assertJsonPath('data.role_label', 'Administrator')
            ->assertJsonPath('data.permissions', ['*']);
    });

    it('carries the licence only for an account that drives', function (): void {
        $this->actingAs($this->driver)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.licence_no', 'N02-14-882301');

        $this->actingAs($this->admin)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.licence_no', null);
    });
});

describe('the navigation endpoint', function (): void {
    it('gives the back office its modules and the driver app its tabs', function (): void {
        $web = $this->actingAs($this->admin)->getJson('/api/v1/navigation')->assertOk();
        $mobile = $this->actingAs($this->driver)
            ->getJson('/api/v1/navigation?client=mobile')
            ->assertOk();

        $webKeys = collect($web->json('data'))->pluck('key');
        $mobileKeys = collect($mobile->json('data'))->pluck('key');

        expect($webKeys)->toContain('billing', 'profitability')
            ->and($mobileKeys)->toContain('cargo', 'tracking')
            // Billing is not a thing a driver does from the cab.
            ->and($mobileKeys)->not->toContain('billing');
    });

    it('filters by permission rather than by role name', function (): void {
        $keys = collect(
            $this->actingAs($this->driver)->getJson('/api/v1/navigation')->json('data')
        )->pluck('key');

        // A driver holds `trips.view` but not `finance.view`.
        expect($keys)->toContain('trips')->not->toContain('profitability');
    });

    it('sorts by order and sends an icon name, never a URL', function (): void {
        $items = $this->actingAs($this->admin)->getJson('/api/v1/navigation')->json('data');

        $orders = array_column($items, 'order');
        $sorted = $orders;
        sort($sorted);

        expect($orders)->toBe($sorted);

        foreach ($items as $item) {
            expect($item['icon'])->not->toContain('/')->not->toContain('http');
        }
    });

    it('omits a badge rather than sending a zero', function (): void {
        $items = $this->actingAs($this->admin)->getJson('/api/v1/navigation')->json('data');

        foreach ($items as $item) {
            expect($item['badge'])->not->toBe(0);
        }
    });
});

describe('cookie auth from an unconfigured origin', function (): void {
    it('explains itself instead of throwing a 500', function (): void {
        // No Origin header, so Sanctum does not treat this as first-party and
        // no session store is attached. Asking for a cookie here cannot work —
        // the reply has to say why rather than blow up in the session layer.
        $this->postJson('/api/v1/login', [
            'email' => 'admin@cargorush.ph',
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_name');
    });

    it('still issues a token to the same caller when one is asked for', function (): void {
        // A device does not need a session, so the identical request with a
        // device_name is fine from anywhere.
        $this->postJson('/api/v1/login', [
            'email' => 'admin@cargorush.ph',
            'password' => 'password',
            'device_name' => 'cli',
        ])
            ->assertCreated()
            ->assertJsonPath('meta.token_type', 'Bearer');
    });
});
