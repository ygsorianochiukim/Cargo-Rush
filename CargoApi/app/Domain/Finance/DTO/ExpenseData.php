<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

/** Money arrives as integer centavos; there is no peso float on this path. */
final class ExpenseData extends Data
{
    public function __construct(
        public readonly ?string $category_id = null,
        public readonly ?string $truck_id = null,
        public readonly ?string $trip_id = null,
        public readonly ?string $vehicle_id = null,
        public readonly ?string $driver_id = null,
        public readonly ?string $date = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $currency = null,
        public readonly ?string $payee = null,
        public readonly ?string $reference = null,
        public readonly ?string $note = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            category_id: $attributes['category_id'] ?? null,
            truck_id: $attributes['truck_id'] ?? null,
            trip_id: $attributes['trip_id'] ?? null,
            vehicle_id: $attributes['vehicle_id'] ?? null,
            driver_id: $attributes['driver_id'] ?? null,
            date: $attributes['date'] ?? null,
            amount_cents: isset($attributes['amount_cents']) ? (int) $attributes['amount_cents'] : null,
            currency: $attributes['currency'] ?? null,
            payee: $attributes['payee'] ?? null,
            reference: $attributes['reference'] ?? null,
            note: $attributes['note'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->category_id,
            'truck_id' => $this->truck_id,
            'trip_id' => $this->trip_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'date' => $this->date,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'payee' => $this->payee,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status?->value,
        ];
    }
}
