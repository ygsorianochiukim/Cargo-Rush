<?php

declare(strict_types=1);

namespace App\Domain\Identity\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * A login attempt.
 *
 * `device_name` is the switch DESIGN.md section 7.4 describes: present means
 * the caller wants a bearer token (mobile), absent means set the SPA cookie
 * (web). One endpoint, two auth styles.
 */
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

    /** True when the caller wants a token rather than a session cookie. */
    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }

    /** Only the part `Auth::attempt()` should ever see. */
    public function onlyCredentials(): array
    {
        return ['email' => $this->email, 'password' => $this->password];
    }
}
