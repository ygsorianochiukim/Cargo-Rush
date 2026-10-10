<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class ExpenseCategoryData extends Data
{
    public function __construct(
        public readonly ?string $key = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly ?int $position = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $account_code = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            key: isset($attributes['key']) ? mb_strtolower(trim((string) $attributes['key'])) : null,
            name: $attributes['name'] ?? null,
            description: $attributes['description'] ?? null,
            icon: $attributes['icon'] ?? null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            account_code: isset($attributes['account_code']) ? trim((string) $attributes['account_code']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'position' => $this->position,
            'status' => $this->status?->value,
            'account_code' => $this->account_code,
        ];
    }
}
