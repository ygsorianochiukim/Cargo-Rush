<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use App\Domain\Shared\Enums\Role;
use Illuminate\Support\Facades\Hash;

/**
 * The login behind a customer record.
 *
 * `cargo:user` makes one of these from the other end — somebody at a terminal,
 * choosing a password. This is the office end: a customer added in Customer
 * Management gets an account with it, because a firm that cannot sign in
 * cannot book its own work or look at its own invoices, and the alternative
 * was the desk asking a developer to run an artisan command for every new
 * customer.
 *
 * The password is the configured starting one, the same for every customer
 * (`config/cargo.php`, `portal.default_password`). That is a real trade: it is
 * not a secret, and anyone at the office who has added one customer knows
 * every new customer's password. It buys the thing that was missing — a firm
 * can be handed credentials the moment it is on the books — and it is the
 * reason `ensureFor()` never touches a password that already exists, so a
 * customer who has changed theirs keeps it.
 */
class CustomerAccountService
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * Make sure this firm can sign in at this address, and say what with.
     *
     * Idempotent, and deliberately conservative: it creates an account only
     * when the firm has none. So an edit grants a login to a firm that never
     * had one — which is how the customers already on the books get theirs —
     * without ever resetting the password of a firm that has one.
     *
     * The candidate is whatever the caller has to offer: the address the
     * office typed, or on a create the contact, which for most firms is
     * already an address. Returns null when there is nothing to build an
     * account from — a phone number is not a login, and a firm the desk only
     * ever rings simply has no account until somebody gives it one.
     *
     * @return array{email: string, password: string}|null What to hand over,
     *                                                     or null if nothing was created.
     */
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

    /**
     * @return array{email: string, password: string}|null
     */
    private function create(Customer $customer, string $address): ?array
    {
        // Already somebody's account. The form validates this and says so, but
        // the fallback to `contact` does not go through that rule, and taking
        // over an existing account — or attaching a second firm to it — is a
        // worse outcome than a customer without a login.
        if ($this->users->findByEmail($address) !== null) {
            return null;
        }

        $password = (string) config('cargo.portal.default_password');

        User::create([
            // The firm's name, because the person is not known yet. Whoever
            // signs in can be renamed later; the account is the firm's.
            'name' => $customer->name,
            'email' => $address,
            'password' => Hash::make($password),
            'role' => Role::Customer->value,
            'customer_id' => $customer->id,
        ]);

        return ['email' => $address, 'password' => $password];
    }
}
