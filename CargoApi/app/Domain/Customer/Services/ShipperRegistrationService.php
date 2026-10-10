<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\DTO\ShipperRegistrationData;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipperRegistrationService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly AuthService $auth,
    ) {}

    /** @return array{user: User, token: string|null} */
    public function register(ShipperRegistrationData $data, Request $request): array
    {
        $this->tenant->nobody();

        $user = DB::transaction(fn (): User => User::create([
            ...$data->userAttributes(),
            'role' => Role::Customer->value,
            'customer_id' => null,
            'chooses_carrier' => true,
        ]));

        $token = $this->auth->establish($user, $data->device_name, $request);

        return ['user' => $user, 'token' => $token];
    }
}
