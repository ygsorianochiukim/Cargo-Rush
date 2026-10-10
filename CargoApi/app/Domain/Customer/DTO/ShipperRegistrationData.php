<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTO;

use App\Domain\Shared\DTO\Data;

final class ShipperRegistrationData extends Data
{
    public function __construct(
        public readonly string $name = '',
        public readonly ?string $contact_phone = null,
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: (string) ($attributes['name'] ?? ''),
            contact_phone: $attributes['contact_phone'] ?? null,
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact_phone' => $this->contact_phone,
            'email' => $this->email,
            'password' => $this->password,
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

    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}
