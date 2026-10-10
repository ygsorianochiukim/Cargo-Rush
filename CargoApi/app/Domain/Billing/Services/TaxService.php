<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\TaxBreakdown;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Support\RateBook;

class TaxService
{
    public function __construct(private readonly RateBook $rates) {}

    public function on(
        int $amountCents,
        ?Customer $customer = null,
        InvoiceDirection $direction = InvoiceDirection::Receivable,
    ): TaxBreakdown {
        if ($direction === InvoiceDirection::Payable) {
            return $this->untaxed($amountCents);
        }

        $treatment = $customer?->vatTreatment() ?? VatTreatment::Vatable;
        $vatRate = $this->charges($treatment) ? $this->vatRateBp() : 0;
        $withholdingRate = $customer?->withholdsTax() === true ? $this->withholdingRateBp($customer) : 0;

        return $this->atRates($amountCents, $vatRate, $withholdingRate, $treatment);
    }

    public function atRates(
        int $amountCents,
        int $vatRateBp,
        int $withholdingRateBp,
        VatTreatment $treatment = VatTreatment::Vatable,
    ): TaxBreakdown {
        $inclusive = $this->rates->pricesIncludeVat();

        if ($inclusive && $vatRateBp > 0) {
            $net = self::roundDiv($amountCents * 10_000, 10_000 + $vatRateBp);
            $vat = $amountCents - $net;
        } else {
            $net = $amountCents;
            $vat = self::roundDiv($net * $vatRateBp, 10_000);
        }

        $withholding = self::roundDiv($net * $withholdingRateBp, 10_000);

        return new TaxBreakdown(
            net_cents: $net,
            vat_cents: $vat,
            withholding_cents: $withholding,
            vat_rate_bp: $vatRateBp,
            withholding_rate_bp: $withholdingRateBp,
            treatment: $treatment,
        );
    }

    public static function roundDiv(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;

        return $sign * intdiv(2 * abs($numerator) + abs($denominator), 2 * abs($denominator));
    }

    public function untaxed(int $amountCents): TaxBreakdown
    {
        return new TaxBreakdown(
            net_cents: $amountCents,
            vat_cents: 0,
            withholding_cents: 0,
            vat_rate_bp: 0,
            withholding_rate_bp: 0,
            treatment: VatTreatment::Exempt,
        );
    }

    private function charges(VatTreatment $treatment): bool
    {
        return $treatment->charges() && $this->rates->chargesVat();
    }

    private function vatRateBp(): int
    {
        return $this->rates->vatRateBp();
    }

    private function withholdingRateBp(Customer $customer): int
    {
        return $customer->withholding_rate_bp ?? $this->rates->withholdingRateBp();
    }
}
