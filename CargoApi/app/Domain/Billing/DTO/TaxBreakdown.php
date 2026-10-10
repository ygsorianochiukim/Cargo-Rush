<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Domain\Shared\Enums\VatTreatment;

final class TaxBreakdown
{
    public function __construct(
        public readonly int $net_cents,
        public readonly int $vat_cents,
        public readonly int $withholding_cents,
        public readonly int $vat_rate_bp,
        public readonly int $withholding_rate_bp,
        public readonly VatTreatment $treatment,
    ) {}

    public function grossCents(): int
    {
        return $this->net_cents + $this->vat_cents;
    }

    public function dueCents(): int
    {
        return $this->grossCents() - $this->withholding_cents;
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        return [
            'net_amount_cents' => $this->net_cents,
            'vat_cents' => $this->vat_cents,
            'amount_cents' => $this->grossCents(),
            'withholding_cents' => $this->withholding_cents,
            'vat_rate_bp' => $this->vat_rate_bp,
            'withholding_rate_bp' => $this->withholding_rate_bp,
            'vat_treatment' => $this->treatment->value,
        ];
    }
}
