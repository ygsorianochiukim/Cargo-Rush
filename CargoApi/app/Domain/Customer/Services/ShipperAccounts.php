<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShipperAccounts
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly NotificationService $notifications,
    ) {}

    /** @return Collection<string, Customer> */
    public function all(User $user): Collection
    {
        $ids = $this->accountIds($user);

        if ($ids === []) {
            return new Collection;
        }

        return Customer::acrossCompanies()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get()
            ->keyBy('company_id');
    }

    /** @return string[] */
    public function carrierIds(User $user): array
    {
        return $this->all($user)->keys()->map(static fn ($id): string => (string) $id)->all();
    }

    public function home(User $user): Customer
    {
        $accounts = $this->all($user);

        $home = $user->customer_id === null
            ? $accounts->first()
            : $accounts->firstWhere('id', $user->customer_id) ?? $accounts->first();

        abort_if($home === null, 404, 'This account is not linked to a customer record.');

        return $home;
    }

    public function at(User $user, Company $carrier): ?Customer
    {
        return $this->all($user)->get($carrier->getKey());
    }

    public function open(User $user, Company $carrier): Customer
    {
        $existing = $this->at($user, $carrier);

        if ($existing !== null) {
            return $existing;
        }

        $known = $this->all($user)->first();

        return DB::transaction(fn (): Customer => $this->tenant->use($carrier, function () use ($user, $known, $carrier): Customer {
            $customer = Customer::create([
                'name' => $known?->name ?? $user->name,
                'contact' => $known?->contact ?? ($user->phone ?: $user->email),
                'address' => $known?->address,
                'latitude' => $known?->latitude,
                'longitude' => $known?->longitude,
                'status' => StatusValue::Active->value,
            ]);

            $this->link($user, $customer);
            $this->settle($user, $customer, $carrier);
            $this->tellTheDesk($customer);

            return $customer;
        }));
    }

    private function settle(User $user, Customer $customer, Company $carrier): void
    {
        if ($user->company_id !== null) {
            return;
        }

        $user->forceFill([
            'company_id' => $carrier->getKey(),
            'customer_id' => $customer->getKey(),
        ])->save();

        $user->setRelation('company', $carrier);
        $user->setRelation('customer', $customer);
    }

    private function tellTheDesk(Customer $customer): void
    {
        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Dispatcher],
            icon: 'customers',
            title: 'New customer registered',
            detail: $customer->name.' chose you in the app',
            tone: Tone::Info,
        );
    }

    public function link(User $user, Customer $customer): void
    {
        DB::table('customer_user')->insertOrIgnore([
            'customer_id' => $customer->getKey(),
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return string[] */
    private function accountIds(User $user): array
    {
        $linked = DB::table('customer_user')
            ->where('user_id', $user->getKey())
            ->pluck('customer_id')
            ->all();

        $ids = $user->customer_id === null ? $linked : [...$linked, $user->customer_id];

        return array_values(array_unique(array_map(static fn ($id): string => (string) $id, $ids)));
    }
}
