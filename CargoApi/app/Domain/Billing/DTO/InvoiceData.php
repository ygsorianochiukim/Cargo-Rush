<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;

final class InvoiceData extends Data
{
    public function __construct(
        public readonly ?string $number = null,
        public readonly ?string $customer_id = null,
        public readonly ?string $payee = null,
        public readonly ?string $issued_at = null,
        public readonly ?string $due_at = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $currency = null,
        public readonly ?InvoiceDirection $direction = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            number: $attributes['number'] ?? null,
            customer_id: $attributes['customer_id'] ?? null,
            payee: $attributes['payee'] ?? null,
            issued_at: $attributes['issued_at'] ?? null,
            due_at: $attributes['due_at'] ?? null,
            amount_cents: isset($attributes['amount_cents']) ? (int) $attributes['amount_cents'] : null,
            currency: $attributes['currency'] ?? null,
            direction: isset($attributes['direction']) ? InvoiceDirection::from($attributes['direction']) : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'customer_id' => $this->customer_id,
            'payee' => $this->payee,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'direction' => $this->direction?->value,
            'status' => $this->status?->value,
        ];
    }
}
