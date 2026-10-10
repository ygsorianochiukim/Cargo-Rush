<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

use App\Domain\Shared\DTO\Data;

final class TruckData extends Data
{
    public function __construct(
        public readonly ?string $label = null,
        public readonly ?string $plate = null,
        public readonly ?string $vehicle_id = null,
        public readonly ?int $position = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            label: $attributes['label'] ?? null,
            plate: $attributes['plate'] ?? null,
            vehicle_id: $attributes['vehicle_id'] ?? null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'plate' => $this->plate,
            'vehicle_id' => $this->vehicle_id,
            'position' => $this->position,
        ];
    }
}
