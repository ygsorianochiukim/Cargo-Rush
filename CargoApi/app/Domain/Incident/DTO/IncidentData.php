<?php

declare(strict_types=1);

namespace App\Domain\Incident\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class IncidentData extends Data
{
    public function __construct(
        public readonly ?string $kind = null,
        public readonly ?string $place = null,
        public readonly ?string $occurred_at = null,
        public readonly ?string $driver_id = null,
        public readonly ?string $vehicle_id = null,
        public readonly ?string $trip_id = null,
        public readonly ?string $notes = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            kind: $attributes['kind'] ?? null,
            place: $attributes['place'] ?? null,
            occurred_at: $attributes['occurred_at'] ?? null,
            driver_id: $attributes['driver_id'] ?? null,
            vehicle_id: $attributes['vehicle_id'] ?? null,
            trip_id: $attributes['trip_id'] ?? null,
            notes: $attributes['notes'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'place' => $this->place,
            'occurred_at' => $this->occurred_at,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'trip_id' => $this->trip_id,
            'notes' => $this->notes,
            'status' => $this->status?->value,
        ];
    }
}
