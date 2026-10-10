<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\DTO;

use App\Domain\Shared\DTO\Data;

final class RegistrationData extends Data
{
    public function __construct(
        public readonly string $company_name,
        public readonly ?string $contact_phone = null,
        public readonly ?string $address = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly string $name = '',
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            company_name: (string) ($attributes['company_name'] ?? ''),
            contact_phone: $attributes['contact_phone'] ?? null,
            address: $attributes['address'] ?? null,
            latitude: isset($attributes['latitude']) ? (float) $attributes['latitude'] : null,
            longitude: isset($attributes['longitude']) ? (float) $attributes['longitude'] : null,
            name: (string) ($attributes['name'] ?? ''),
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'company_name' => $this->company_name,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'device_name' => $this->device_name,
        ];
    }

    public function companyAttributes(): array
    {
        return [
            'name' => $this->company_name,
            'contact_name' => $this->name,
            'contact_email' => $this->email,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}
