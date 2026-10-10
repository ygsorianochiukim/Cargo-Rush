<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\BalanceSide;

final class JournalLineData extends Data
{
    public function __construct(
        public readonly string $account_id = '',
        public readonly BalanceSide $side = BalanceSide::Debit,
        public readonly int $amount_cents = 0,
        public readonly ?string $memo = null,
        public readonly ?string $truck_id = null,
        public readonly ?string $trip_id = null,
        public readonly ?string $customer_id = null,
        public readonly int $line_no = 1,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            account_id: (string) ($attributes['account_id'] ?? ''),
            side: BalanceSide::from((string) ($attributes['side'] ?? BalanceSide::Debit->value)),
            amount_cents: (int) ($attributes['amount_cents'] ?? 0),
            memo: $attributes['memo'] ?? null,
            truck_id: $attributes['truck_id'] ?? null,
            trip_id: $attributes['trip_id'] ?? null,
            customer_id: $attributes['customer_id'] ?? null,
            line_no: (int) ($attributes['line_no'] ?? 1),
        );
    }

    public function toArray(): array
    {
        return [
            'account_id' => $this->account_id,
            'side' => $this->side->value,
            'amount_cents' => $this->amount_cents,
            'memo' => $this->memo,
            'truck_id' => $this->truck_id,
            'trip_id' => $this->trip_id,
            'customer_id' => $this->customer_id,
            'line_no' => $this->line_no,
        ];
    }

    public function isDebit(): bool
    {
        return $this->side === BalanceSide::Debit;
    }

    public function debitCents(): int
    {
        return $this->isDebit() ? $this->amount_cents : 0;
    }

    public function creditCents(): int
    {
        return $this->isDebit() ? 0 : $this->amount_cents;
    }

    /** @return array<string, mixed> */
    public function columns(int $lineNo): array
    {
        return [
            'account_id' => $this->account_id,
            'line_no' => $lineNo,
            'debit_cents' => $this->debitCents(),
            'credit_cents' => $this->creditCents(),
            'memo' => $this->memo,
            'truck_id' => $this->truck_id,
            'trip_id' => $this->trip_id,
            'customer_id' => $this->customer_id,
        ];
    }

    public function atLine(int $lineNo): self
    {
        return new self(
            account_id: $this->account_id,
            side: $this->side,
            amount_cents: $this->amount_cents,
            memo: $this->memo,
            truck_id: $this->truck_id,
            trip_id: $this->trip_id,
            customer_id: $this->customer_id,
            line_no: $lineNo,
        );
    }
}
