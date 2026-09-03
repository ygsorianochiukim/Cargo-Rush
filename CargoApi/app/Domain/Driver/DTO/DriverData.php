<?php

declare(strict_types=1);

namespace App\Domain\Driver\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class DriverData extends Data
{
    public function __construct(
        public readonly ?int $user_id = null,
        public readonly ?string $name = null,
        public readonly ?string $licence_no = null,
        public readonly ?string $licence_expiry = null,
        public readonly ?int $violations = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            user_id: isset($attributes['user_id']) ? (int) $attributes['user_id'] : null,
            name: $attributes['name'] ?? null,
            licence_no: $attributes['licence_no'] ?? null,
            licence_expiry: $attributes['licence_expiry'] ?? null,
            violations: isset($attributes['violations']) ? (int) $attributes['violations'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->name,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
            'violations' => $this->violations,
            'status' => $this->status?->value,
        ];
    }
}
