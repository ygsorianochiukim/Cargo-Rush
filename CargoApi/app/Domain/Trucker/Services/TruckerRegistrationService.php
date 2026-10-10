<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trucker\DTO\TruckerRegistrationData;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TruckerRegistrationService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly AuthService $auth,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{user: User, trucker: Trucker, token: string|null} */
    public function register(TruckerRegistrationData $data, Request $request): array
    {
        $carrier = $this->carrier($data);

        $result = $this->tenant->use($carrier, fn (): array => DB::transaction(function () use ($data, $carrier): array {
            $this->mustBeNewHere($data->licence_no);

            $user = User::create([
                ...$data->userAttributes(),
                'role' => Role::Trucker->value,
                'company_id' => $carrier->getKey(),
            ]);

            $trucker = Trucker::create([
                ...$data->truckerAttributes(),
                'user_id' => $user->getKey(),
            ]);

            return ['user' => $user, 'trucker' => $trucker->refresh()];
        }));

        $token = $this->auth->establish($result['user'], $data->device_name, $request);

        $this->tellTheDesk($carrier, $result['trucker']);

        return [...$result, 'token' => $token];
    }

    private function mustBeNewHere(?string $licenceNo): void
    {
        if ($licenceNo === null) {
            return;
        }

        $exists = Trucker::withTrashed()->where('licence_no', $licenceNo)->exists();

        abort_if(
            $exists,
            422,
            'That licence is already registered with this fleet. Sign in instead, or contact the office.',
        );
    }

    private function carrier(TruckerRegistrationData $data): Company
    {
        $carrier = $data->company_id !== null && $data->company_id !== ''
            ? Company::query()->find($data->company_id)
            : $this->hostFleet();

        abort_if($carrier === null, 404, 'That fleet could not be found.');
        abort_unless(
            $carrier->status->value === 'active',
            422,
            'Registrations are closed at the moment. Try again shortly.',
        );

        return $carrier;
    }

    private function hostFleet(): ?Company
    {
        $code = config('cargo.truckers.registers_with');

        if (is_string($code) && $code !== '') {
            return Company::query()->where('code', $code)->first();
        }

        return Company::query()->oldest()->first();
    }

    private function tellTheDesk(Company $carrier, Trucker $trucker): void
    {
        $this->tenant->use($carrier, function () use ($trucker): void {
            $this->notifications->pushToRoles(
                roles: [Role::Administrator, Role::Dispatcher],
                icon: 'fleet',
                title: 'New trucker registered',
                detail: ($trucker->business_name ? "{$trucker->business_name} ({$trucker->name})" : $trucker->name)
                    .' is waiting to be approved',
                tone: Tone::Warning,
            );
        });
    }
}
