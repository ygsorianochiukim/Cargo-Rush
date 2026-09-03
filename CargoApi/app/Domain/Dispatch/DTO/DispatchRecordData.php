<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class DispatchRecordData extends Data
{
    public function __construct(
        public readonly ?string $trip_id = null,
        public readonly ?string $vehicle_id = null,
        public readonly ?string $dispatched_at = null,
        public readonly ?string $location = null,
        public readonly ?string $arrived_at = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            trip_id: $attributes['trip_id'] ?? null,
            vehicle_id: $attributes['vehicle_id'] ?? null,
            dispatched_at: $attributes['dispatched_at'] ?? null,
            location: $attributes['location'] ?? null,
            arrived_at: $attributes['arrived_at'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'trip_id' => $this->trip_id,
            'vehicle_id' => $this->vehicle_id,
            'dispatched_at' => $this->dispatched_at,
            'location' => $this->location,
            'arrived_at' => $this->arrived_at,
            'status' => $this->status?->value,
        ];
    }
}
