<?php

declare(strict_types=1);

namespace App\Domain\Identity\DTO;

use App\Domain\Shared\DTO\Data;

final class CredentialsData extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
            'device_name' => $this->device_name,
        ];
    }

    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }

    public function onlyCredentials(): array
    {
        return ['email' => $this->email, 'password' => $this->password];
    }
}
