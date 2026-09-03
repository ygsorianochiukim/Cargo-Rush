<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\DTO\CredentialsData;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum, both ways.
 *
 * One `POST /login`: with a `device_name` the caller gets a bearer token
 * (cargoApp), without one it gets the SPA session cookie (CargoUI). That is
 * DESIGN.md section 7.4, and it is the only place either is issued.
 */
class AuthService
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * @return array{user: User, token: string|null}
     *
     * @throws ValidationException
     */
    public function login(CredentialsData $credentials, Request $request): array
    {
        $user = $this->users->findByEmail($credentials->email);

        // One message for both a wrong address and a wrong password, so the
        // response cannot be used to find out which accounts exist.
        if ($user === null || ! Hash::check($credentials->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($credentials->wantsToken()) {
            // A fresh login for a device replaces that device's old token
            // rather than stacking another one on the pile.
            $user->tokens()->where('name', $credentials->device_name)->delete();

            return [
                'user' => $user,
                'token' => $user->createToken($credentials->device_name, $user->permissions())->plainTextToken,
            ];
        }

        // No session store means Sanctum did not treat this as a first-party
        // request — the caller's host is not in SANCTUM_STATEFUL_DOMAINS. There
        // is no cookie to set, and calling session() here would be a 500 with a
        // stack trace instead of the one sentence that fixes it.
        if (! $request->hasSession()) {
            throw ValidationException::withMessages([
                'device_name' => [
                    'This origin cannot use cookie authentication. Send a device_name to '
                    .'receive a token, or add this host to SANCTUM_STATEFUL_DOMAINS.',
                ],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return ['user' => $user, 'token' => null];
    }

    public function logout(Request $request): void
    {
        $user = $request->user();

        // Token auth: drop just the token that made this call. Session auth:
        // end the session. Doing both would log a driver out of every device.
        if ($user?->currentAccessToken() !== null) {
            $user->currentAccessToken()->delete();

            return;
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * @throws AuthenticationException
     */
    public function requireUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
