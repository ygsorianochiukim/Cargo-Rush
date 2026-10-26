<?php

declare(strict_types=1);

namespace App\Domain\Driver\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\CrewEmployment;
use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Enums\StatusValue;

final class DriverData extends Data
{
    public function __construct(
        public readonly ?int $user_id = null,
        public readonly ?CrewRole $role = null,
        public readonly ?CrewEmployment $employment = null,
        public readonly ?string $name = null,
        public readonly ?string $contact = null,
        public readonly ?string $licence_no = null,
        public readonly ?string $licence_expiry = null,
        public readonly ?int $violations = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            user_id: isset($attributes['user_id']) ? (int) $attributes['user_id'] : null,
            role: isset($attributes['role']) ? CrewRole::from($attributes['role']) : null,
            employment: isset($attributes['employment']) ? CrewEmployment::from($attributes['employment']) : null,
            name: $attributes['name'] ?? null,
            contact: $attributes['contact'] ?? null,
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
            'role' => $this->role?->value,
            'employment' => $this->employment?->value,
            'name' => $this->name,
            'contact' => $this->contact,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
            'violations' => $this->violations,
            'status' => $this->status?->value,
        ];
    }
}
