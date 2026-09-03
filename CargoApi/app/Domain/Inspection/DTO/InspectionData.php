<?php

declare(strict_types=1);

namespace App\Domain\Inspection\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * A submitted pre-trip check. `good_to_go` is not taken from the client — the
 * service derives it from the results so the call cannot be overridden.
 */
final class InspectionData extends Data
{
    /**
     * @param  array<string, bool>|null  $results
     */
    public function __construct(
        public readonly ?string $trip_id = null,
        public readonly ?string $vehicle_id = null,
        public readonly ?string $driver_id = null,
        public readonly ?array $results = null,
        public readonly ?string $notes = null,
        public readonly ?string $inspected_at = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            trip_id: $attributes['trip_id'] ?? null,
            vehicle_id: $attributes['vehicle_id'] ?? null,
            driver_id: $attributes['driver_id'] ?? null,
            results: isset($attributes['results']) ? array_map(
                static fn ($v): bool => (bool) $v,
                (array) $attributes['results'],
            ) : null,
            notes: $attributes['notes'] ?? null,
            inspected_at: $attributes['inspected_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'trip_id' => $this->trip_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'results' => $this->results,
            'notes' => $this->notes,
            'inspected_at' => $this->inspected_at,
        ];
    }
}
