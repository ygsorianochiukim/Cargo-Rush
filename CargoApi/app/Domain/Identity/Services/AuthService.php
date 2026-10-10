<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\DTO\CredentialsData;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array{user: User, token: string|null}
     *
     * @throws ValidationException
     */
    public function login(CredentialsData $credentials, Request $request): array
    {
        $user = $this->users->findByEmail($credentials->email);

        if ($user === null || ! Hash::check($credentials->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $this->enterCompany($user);

        return ['user' => $user, 'token' => $this->establish($user, $credentials->device_name, $request)];
    }

    /** @throws ValidationException */
    private function enterCompany(User $user): void
    {
        $company = $user->company;

        if ($company === null && $user->awaitingCarrier()) {
            $this->tenant->nobody();

            return;
        }

        if ($company === null) {
            throw ValidationException::withMessages([
                'email' => ['This account is not attached to a company. Ask an administrator to reattach it.'],
            ]);
        }

        if (! $company->isActive()) {
            throw ValidationException::withMessages([
                'email' => [sprintf('%s is suspended. Contact support to reactivate the account.', $company->name)],
            ]);
        }

        $this->tenant->set($company);
    }

    /**
     * @throws ValidationException
     */
    public function establish(User $user, ?string $deviceName, Request $request): ?string
    {
        if ($deviceName !== null && $deviceName !== '') {
            $user->tokens()->where('name', $deviceName)->delete();

            return $user->createToken($deviceName, $user->permissions())->plainTextToken;
        }

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

        return null;
    }

    public function logout(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** @throws AuthenticationException */
    public function requireUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
