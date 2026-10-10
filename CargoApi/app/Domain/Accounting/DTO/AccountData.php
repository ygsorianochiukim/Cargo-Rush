<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\StatusValue;

final class AccountData extends Data
{
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?string $name = null,
        public readonly ?AccountType $type = null,
        public readonly ?string $group = null,
        public readonly ?string $description = null,
        public readonly ?int $position = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            code: isset($attributes['code']) ? trim((string) $attributes['code']) : null,
            name: $attributes['name'] ?? null,
            type: isset($attributes['type']) ? AccountType::from((string) $attributes['type']) : null,
            group: $attributes['group'] ?? null,
            description: $attributes['description'] ?? null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
            status: isset($attributes['status']) ? StatusValue::from((string) $attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type?->value,
            'group' => $this->group,
            'description' => $this->description,
            'position' => $this->position,
            'status' => $this->status?->value,
        ];
    }
}
