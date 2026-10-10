<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\DTO\RegistrationData;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly CompanyProvisioner $provisioner,
        private readonly AuthService $auth,
    ) {}

    /** @return array{company: Company, user: User, token: string|null} */
    public function register(RegistrationData $data, Request $request): array
    {
        /** @var array{company: Company, user: User} $created */
        $created = DB::transaction(function () use ($data): array {
            $company = Company::create([
                ...$data->companyAttributes(),
                'code' => Company::codeFor($data->company_name),
            ]);

            $this->provisioner->provision($company);

            $user = $this->tenant->use($company, fn (): User => User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'role' => Role::Administrator->value,
            ]));

            return ['company' => $company, 'user' => $user];
        });

        $this->tenant->set($created['company']);

        $token = $this->auth->establish($created['user'], $data->device_name, $request);

        return [...$created, 'token' => $token];
    }
}
