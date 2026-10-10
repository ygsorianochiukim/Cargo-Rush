<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTO;

use App\Domain\Shared\DTO\Data;

final class LicenceData extends Data
{
    public function __construct(
        public readonly ?string $licence_no = null,
        public readonly ?string $licence_expiry = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            licence_no: $attributes['licence_no'] ?? null,
            licence_expiry: $attributes['licence_expiry'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
        ];
    }

    public function hasLicence(): bool
    {
        return $this->licence_no !== null && $this->licence_no !== '';
    }
}
