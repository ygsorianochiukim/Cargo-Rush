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
        public readonly ?string $trip_id = null,
        /**
         * Who it was bought from.
         *
         * Replaces the typing. `payee` is still here below and still written —
         * it holds what was already typed, and a tyre bought once in Tagum from
         * somebody nobody will see again does not deserve a supplier record.
         */
        public readonly ?string $supplier_id = null,
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
            trip_id: $attributes['trip_id'] ?? null,
            supplier_id: $attributes['supplier_id'] ?? null,
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
            'trip_id' => $this->trip_id,
            'supplier_id' => $this->supplier_id,
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
