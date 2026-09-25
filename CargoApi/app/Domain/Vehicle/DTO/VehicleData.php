<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;

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
        /**
         * On what terms this truck runs, and who is paid for it.
         *
         * Every unit dispatches identically whoever owns the wheels; these
         * decide only where the money goes when a run closes. See
         * `VehicleArrangement`.
         */
        public readonly ?VehicleArrangement $arrangement = null,
        public readonly ?int $wheels = null,
        public readonly ?string $owner_name = null,
        public readonly ?string $owner_contact = null,
        public readonly ?int $rent_cents = null,
        public readonly ?int $share_bp = null,
        public readonly ?string $owner_trucker_id = null,
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
            arrangement: isset($attributes['arrangement'])
                ? VehicleArrangement::from($attributes['arrangement'])
                : null,
            // Cast only when present: `null` is a real instruction on a PATCH
            // — "this truck has no rent" — and `(int) null` would write zero.
            wheels: isset($attributes['wheels']) ? (int) $attributes['wheels'] : null,
            owner_name: $attributes['owner_name'] ?? null,
            owner_contact: $attributes['owner_contact'] ?? null,
            rent_cents: isset($attributes['rent_cents']) ? (int) $attributes['rent_cents'] : null,
            share_bp: isset($attributes['share_bp']) ? (int) $attributes['share_bp'] : null,
            owner_trucker_id: $attributes['owner_trucker_id'] ?? null,
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
            'arrangement' => $this->arrangement?->value,
            'wheels' => $this->wheels,
            'owner_name' => $this->owner_name,
            'owner_contact' => $this->owner_contact,
            'rent_cents' => $this->rent_cents,
            'share_bp' => $this->share_bp,
            'owner_trucker_id' => $this->owner_trucker_id,
        ];
    }
}
