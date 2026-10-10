<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Company;

class RateBook
{
    private ?Company $subject = null;

    public function __construct(private readonly Tenant $tenant) {}

    public function for(?Company $company): self
    {
        $book = clone $this;
        $book->subject = $company;

        return $book;
    }

    public function truckerCommissionBp(): int
    {
        return (int) ($this->company()?->trucker_commission_bp
            ?? config('cargo.truckers.commission_bp', 1200));
    }

    public function billingTermsDays(): int
    {
        return (int) ($this->company()?->billing_terms_days ?? config('cargo.billing.terms_days', 30));
    }

    public function vatRateBp(): int
    {
        return (int) ($this->company()?->vat_rate_bp ?? config('cargo.tax.vat_rate_bp', 1200));
    }

    public function chargesVat(): bool
    {
        return (bool) ($this->company()?->vat_registered ?? true);
    }

    public function withholdingRateBp(): int
    {
        return (int) ($this->company()?->withholding_rate_bp
            ?? config('cargo.tax.withholding_rate_bp', 200));
    }

    public function pricesIncludeVat(): bool
    {
        return (bool) ($this->company()?->prices_include_vat
            ?? config('cargo.tax.prices_include_vat', false));
    }

    public function currency(): string
    {
        return (string) config('cargo.currency', 'PHP');
    }

    /** @return array<string, mixed> */
    public function inForce(): array
    {
        return [
            'trucker_commission_bp' => $this->truckerCommissionBp(),
            'billing_terms_days' => $this->billingTermsDays(),
            'vat_registered' => $this->chargesVat(),
            'vat_rate_bp' => $this->vatRateBp(),
            'withholding_rate_bp' => $this->withholdingRateBp(),
            'prices_include_vat' => $this->pricesIncludeVat(),
            'currency' => $this->currency(),
        ];
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'trucker_commission_bp' => (int) config('cargo.truckers.commission_bp', 1200),
            'billing_terms_days' => (int) config('cargo.billing.terms_days', 30),
            'vat_registered' => true,
            'vat_rate_bp' => (int) config('cargo.tax.vat_rate_bp', 1200),
            'withholding_rate_bp' => (int) config('cargo.tax.withholding_rate_bp', 200),
            'prices_include_vat' => (bool) config('cargo.tax.prices_include_vat', false),
            'currency' => $this->currency(),
        ];
    }

    /** @return array<string, mixed> */
    public function overrides(): array
    {
        $company = $this->company();

        return [
            'billing_terms_days' => $company?->billing_terms_days,
            'withholding_rate_bp' => $company?->withholding_rate_bp,
            'prices_include_vat' => $company?->prices_include_vat,
        ];
    }

    private function company(): ?Company
    {
        return $this->subject ?? $this->tenant->company();
    }
}
