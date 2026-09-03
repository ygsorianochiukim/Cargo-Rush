<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

/** Closing out a delivery: who signed, when, and the proof reference. */
final class DeliveryLogData extends Data
{
    public function __construct(
        public readonly ?string $trip_id = null,
        public readonly ?string $delivered_at = null,
        public readonly ?string $pod_ref = null,
        public readonly ?string $receiver_name = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            trip_id: $attributes['trip_id'] ?? null,
            delivered_at: $attributes['delivered_at'] ?? null,
            pod_ref: $attributes['pod_ref'] ?? null,
            receiver_name: $attributes['receiver_name'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'trip_id' => $this->trip_id,
            'delivered_at' => $this->delivered_at,
            'pod_ref' => $this->pod_ref,
            'receiver_name' => $this->receiver_name,
            'status' => $this->status?->value,
        ];
    }
}
