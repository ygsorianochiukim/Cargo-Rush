<?php

declare(strict_types=1);

namespace App\Domain\Fuel\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

/** Money arrives as integer centavos; there is no peso float on this path. */
final class FuelRecordData extends Data
{
    public function __construct(
        public readonly ?string $vehicle_id = null,
        public readonly ?string $driver_id = null,
        public readonly ?float $litres = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $currency = null,
        public readonly ?int $odometer_km = null,
        public readonly ?string $receipt_no = null,
        public readonly ?string $logged_at = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            vehicle_id: $attributes['vehicle_id'] ?? null,
            driver_id: $attributes['driver_id'] ?? null,
            litres: isset($attributes['litres']) ? (float) $attributes['litres'] : null,
            amount_cents: isset($attributes['amount_cents']) ? (int) $attributes['amount_cents'] : null,
            currency: $attributes['currency'] ?? null,
            odometer_km: isset($attributes['odometer_km']) ? (int) $attributes['odometer_km'] : null,
            receipt_no: $attributes['receipt_no'] ?? null,
            logged_at: $attributes['logged_at'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'litres' => $this->litres,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'odometer_km' => $this->odometer_km,
            'receipt_no' => $this->receipt_no,
            'logged_at' => $this->logged_at,
            'status' => $this->status?->value,
        ];
    }
}
