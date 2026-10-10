<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class PricingZoneData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?int $min_km = null,
        public readonly ?int $max_km = null,
        public readonly ?int $diesel_baseline_cents = null,
        public readonly ?int $position = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $notes = null,
        /** @var array<int, array<string, mixed>>|null */
        public readonly ?array $brackets = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: $attributes['name'] ?? null,
            code: isset($attributes['code']) ? mb_strtoupper(trim((string) $attributes['code'])) : null,
            min_km: isset($attributes['min_km']) ? (int) $attributes['min_km'] : null,
            max_km: array_key_exists('max_km', $attributes) && $attributes['max_km'] !== null
                ? (int) $attributes['max_km']
                : null,
            diesel_baseline_cents: isset($attributes['diesel_baseline_cents'])
                ? (int) $attributes['diesel_baseline_cents']
                : null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            notes: $attributes['notes'] ?? null,
            brackets: isset($attributes['brackets']) ? (array) $attributes['brackets'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'min_km' => $this->min_km,
            'max_km' => $this->max_km,
            'diesel_baseline_cents' => $this->diesel_baseline_cents,
            'position' => $this->position,
            'status' => $this->status?->value,
            'notes' => $this->notes,
        ];
    }
}
