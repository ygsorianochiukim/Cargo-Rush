<?php

declare(strict_types=1);

namespace App\Domain\Trucker\DTO;

use App\Domain\Shared\DTO\Data;

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

    /** @return array<string, mixed> */
    public function userAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->contact_phone,
            'password' => $this->password,
        ];
    }

    /** @return array<string, mixed> */
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

    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}
