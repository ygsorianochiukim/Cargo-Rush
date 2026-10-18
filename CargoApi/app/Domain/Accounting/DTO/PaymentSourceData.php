<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\PaymentSourceKind;
use App\Domain\Shared\Enums\StatusValue;

final class PaymentSourceData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?PaymentSourceKind $kind = null,
        public readonly ?string $provider = null,
        public readonly ?string $last_four = null,
        public readonly ?string $note = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: isset($attributes['name']) ? trim((string) $attributes['name']) : null,
            kind: isset($attributes['kind']) ? PaymentSourceKind::from($attributes['kind']) : null,
            provider: $attributes['provider'] ?? null,
            last_four: $attributes['last_four'] ?? null,
            note: $attributes['note'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind?->value,
            'provider' => $this->provider,
            'last_four' => $this->last_four,
            'note' => $this->note,
            'status' => $this->status?->value,
        ];
    }
}
