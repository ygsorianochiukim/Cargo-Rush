<?php

declare(strict_types=1);

namespace App\Domain\Trucker\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * A trucking service signing itself up.
 *
 * Who they are, how to reach them, and a login — nothing else. Trucks and
 * drivers are added from the app once the office has approved the account; see
 * `RegisterTruckerRequest` for why.
 *
 * What it does **not** ask for is which company. See
 * `TruckerRegistrationService` for how that is resolved.
 */
final class TruckerRegistrationData extends Data
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $contact_phone = '',
        public readonly string $business_name = '',
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly ?string $licence_no = null,
        public readonly ?string $licence_expiry = null,
        /** Which haulier they are signing up to haul for. */
        public readonly ?string $company_id = null,
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        $licence = trim((string) ($attributes['licence_no'] ?? ''));

        return new self(
            name: (string) ($attributes['name'] ?? ''),
            contact_phone: (string) ($attributes['contact_phone'] ?? ''),
            business_name: (string) ($attributes['business_name'] ?? ''),
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            // Blank is "none", not an empty licence two owners could collide on.
            licence_no: $licence === '' ? null : $licence,
            licence_expiry: $attributes['licence_expiry'] ?? null,
            company_id: $attributes['company_id'] ?? null,
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact_phone' => $this->contact_phone,
            'business_name' => $this->business_name,
            'email' => $this->email,
            'password' => $this->password,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
            'company_id' => $this->company_id,
            'device_name' => $this->device_name,
        ];
    }

    /**
     * The `users` columns for the login this creates.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->contact_phone,
            // Hashed by the model's cast, as every other write of a password
            // in this codebase is.
            'password' => $this->password,
        ];
    }

    /**
     * The `truckers` columns.
     *
     * No `status`: the column defaults to `pending` and this is the one place
     * it would be tempting to set, which is exactly why it is not passed. A
     * registration that could name its own standing is a registration that
     * could vet itself.
     *
     * @return array<string, mixed>
     */
    public function truckerAttributes(): array
    {
        return [
            'name' => $this->name,
            'business_name' => $this->business_name,
            'phone' => $this->contact_phone,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
        ];
    }

    /** True when the caller wants a bearer token rather than a session cookie. */
    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}
