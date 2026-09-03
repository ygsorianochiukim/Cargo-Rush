<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class VehicleData extends Data
{
    public function __construct(
        public readonly ?string $plate = null,
        public readonly ?string $model = null,
        public readonly ?string $registration_no = null,
        public readonly ?int $capacity_kg = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $driver_id = null,
        public readonly ?int $odometer_km = null,
        public readonly ?int $next_service_km = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            plate: $attributes['plate'] ?? null,
            model: $attributes['model'] ?? null,
            registration_no: $attributes['registration_no'] ?? null,
            capacity_kg: isset($attributes['capacity_kg']) ? (int) $attributes['capacity_kg'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            driver_id: $attributes['driver_id'] ?? null,
            odometer_km: isset($attributes['odometer_km']) ? (int) $attributes['odometer_km'] : null,
            next_service_km: isset($attributes['next_service_km']) ? (int) $attributes['next_service_km'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'plate' => $this->plate,
            'model' => $this->model,
            'registration_no' => $this->registration_no,
            'capacity_kg' => $this->capacity_kg,
            'status' => $this->status?->value,
            'driver_id' => $this->driver_id,
            'odometer_km' => $this->odometer_km,
            'next_service_km' => $this->next_service_km,
        ];
    }
}
