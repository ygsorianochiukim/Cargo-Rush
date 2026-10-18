<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\PaySchedule;
use App\Domain\Shared\Enums\StatusValue;

final class ExpenseData extends Data
{
    public function __construct(
        public readonly ?string $category_id = null,
        public readonly ?string $trip_id = null,
        public readonly ?string $supplier_id = null,
        public readonly ?string $payment_source_id = null,
        public readonly ?PaySchedule $pay_schedule = null,
        public readonly ?string $date = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $currency = null,
        public readonly ?bool $is_vatable = null,
        public readonly ?string $payee = null,
        public readonly ?string $reference = null,
        public readonly ?string $note = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            category_id: $attributes['category_id'] ?? null,
            trip_id: $attributes['trip_id'] ?? null,
            supplier_id: $attributes['supplier_id'] ?? null,
            payment_source_id: $attributes['payment_source_id'] ?? null,
            pay_schedule: isset($attributes['pay_schedule']) ? PaySchedule::from($attributes['pay_schedule']) : null,
            date: $attributes['date'] ?? null,
            amount_cents: isset($attributes['amount_cents']) ? (int) $attributes['amount_cents'] : null,
            currency: $attributes['currency'] ?? null,
            is_vatable: isset($attributes['is_vatable']) ? (bool) $attributes['is_vatable'] : null,
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
            'trip_id' => $this->trip_id,
            'supplier_id' => $this->supplier_id,
            'payment_source_id' => $this->payment_source_id,
            'pay_schedule' => $this->pay_schedule?->value,
            'date' => $this->date,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'is_vatable' => $this->is_vatable,
            'payee' => $this->payee,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status?->value,
        ];
    }
}
