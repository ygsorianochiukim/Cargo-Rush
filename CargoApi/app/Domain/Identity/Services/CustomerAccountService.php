<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\ShipperAccounts;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use App\Domain\Shared\Enums\Role;
use Illuminate\Support\Facades\Hash;

class CustomerAccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ShipperAccounts $accounts,
    ) {}

    /** @return array{email: string, password: string}|null */
    public function ensureFor(Customer $customer, ?string $candidate): ?array
    {
        $address = trim((string) $candidate);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        if ($customer->logins()->exists()) {
            return null;
        }

        return $this->create($customer, $address);
    }

    /** @return array{email: string, password: string}|null */
    private function create(Customer $customer, string $address): ?array
    {
        if ($this->users->findByEmail($address) !== null) {
            return null;
        }

        $password = (string) config('cargo.portal.default_password');

        $user = User::create([
            'name' => $customer->name,
            'email' => $address,
            'password' => Hash::make($password),
            'role' => Role::Customer->value,
            'customer_id' => $customer->id,
        ]);

        $this->accounts->link($user, $customer);

        return ['email' => $address, 'password' => $password];
    }
}
