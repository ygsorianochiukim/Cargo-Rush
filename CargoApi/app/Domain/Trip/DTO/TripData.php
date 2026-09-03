<?php

declare(strict_types=1);

namespace App\Domain\Trip\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

/**
 * What it takes to book, request or amend a trip.
 *
 * `reference` is absent on purpose: the API assigns it (DESIGN.md section 5.3
 * makes it the human-readable id), so a client cannot choose one.
 *
 * `price_cents` is present but almost never sent. The tariff quotes it
 * (`PricingService`), and the only reason it is here at all is the negotiated
 * rate — a price the office agreed by hand, which the service must be able to
 * tell from a price it derived. `Data::wasGiven()` is how it tells.
 */
final class TripData extends Data
{
    public function __construct(
        public readonly ?string $customer_id = null,
        public readonly ?string $origin = null,
        public readonly ?float $origin_lat = null,
        public readonly ?float $origin_lng = null,
        public readonly ?string $destination = null,
        public readonly ?float $destination_lat = null,
        public readonly ?float $destination_lng = null,
        public readonly ?string $cargo = null,
        public readonly ?int $weight_kg = null,
        public readonly ?int $pieces = null,
        public readonly ?string $handling = null,
        public readonly ?int $price_cents = null,
        public readonly ?string $currency = null,
        public readonly ?string $driver_id = null,
        public readonly ?string $helper_id = null,
        public readonly ?string $vehicle_id = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $pickup_place = null,
        public readonly ?string $dropoff_place = null,
        public readonly ?string $scheduled_at = null,
        public readonly ?string $eta = null,
        public readonly ?int $distance_total_m = null,
        /** The account that asked for it, on a customer's own request. */
        public readonly ?int $requested_by = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            customer_id: $attributes['customer_id'] ?? null,
            origin: $attributes['origin'] ?? null,
            origin_lat: isset($attributes['origin_lat']) ? (float) $attributes['origin_lat'] : null,
            origin_lng: isset($attributes['origin_lng']) ? (float) $attributes['origin_lng'] : null,
            destination: $attributes['destination'] ?? null,
            destination_lat: isset($attributes['destination_lat']) ? (float) $attributes['destination_lat'] : null,
            destination_lng: isset($attributes['destination_lng']) ? (float) $attributes['destination_lng'] : null,
            cargo: $attributes['cargo'] ?? null,
            weight_kg: isset($attributes['weight_kg']) ? (int) $attributes['weight_kg'] : null,
            pieces: isset($attributes['pieces']) ? (int) $attributes['pieces'] : null,
            handling: $attributes['handling'] ?? null,
            price_cents: isset($attributes['price_cents']) ? (int) $attributes['price_cents'] : null,
            currency: $attributes['currency'] ?? null,
            driver_id: $attributes['driver_id'] ?? null,
            helper_id: $attributes['helper_id'] ?? null,
            vehicle_id: $attributes['vehicle_id'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            pickup_place: $attributes['pickup_place'] ?? null,
            dropoff_place: $attributes['dropoff_place'] ?? null,
            scheduled_at: $attributes['scheduled_at'] ?? null,
            eta: $attributes['eta'] ?? null,
            distance_total_m: isset($attributes['distance_total_m']) ? (int) $attributes['distance_total_m'] : null,
            requested_by: isset($attributes['requested_by']) ? (int) $attributes['requested_by'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'customer_id' => $this->customer_id,
            'origin' => $this->origin,
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination' => $this->destination,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
            'cargo' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'pieces' => $this->pieces,
            'handling' => $this->handling,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'driver_id' => $this->driver_id,
            'helper_id' => $this->helper_id,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status?->value,
            'pickup_place' => $this->pickup_place,
            'dropoff_place' => $this->dropoff_place,
            'scheduled_at' => $this->scheduled_at,
            'eta' => $this->eta,
            'distance_total_m' => $this->distance_total_m,
            'requested_by' => $this->requested_by,
        ];
    }
}
