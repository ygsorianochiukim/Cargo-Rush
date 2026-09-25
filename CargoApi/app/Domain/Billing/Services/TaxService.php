<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\TaxBreakdown;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Support\RateBook;

/**
 * What tax an invoice carries, worked out once.
 *
 * Three parties each hold part of the answer, and none of them holds all of
 * it — which is why this is a service rather than a method on the invoice:
 *
 *   **The statute** sets the rates — and the office may correct them on the
 *   settings card when a circular moves one, falling back to `config('cargo.tax')`
 *   for an install that has never touched them. `RateBook` answers both.
 *
 *   **The company** decides whether it charges VAT at all. Below the
 *   registration threshold a haulier files percentage tax instead and issues
 *   invoices with no VAT line — putting one on would be charging a tax it has
 *   no authority to collect.
 *
 *   **The customer** decides the rest: whether they are vatable, zero-rated or
 *   exempt, and whether they withhold. Both are properties of who is being
 *   billed, not of what was hauled.
 *
 * The result is **frozen onto the invoice**, rates included. An invoice is a
 * promise made on a date, exactly as a trip's price is, and the reasons are
 * the same: rates change by statute, a company registers for VAT, a customer
 * becomes a withholding agent — and none of that may quietly alter a document
 * somebody is holding a copy of.
 */
class TaxService
{
    public function __construct(private readonly RateBook $rates) {}

    /**
     * Work out the tax on an amount.
     *
     * `$amountCents` is the figure the desk has: the tariff price, or what
     * somebody typed on the billing form. Whether that figure is the net haul
     * or an all-in price the customer was quoted is a business decision, not
     * something to guess — the firm's `prices_include_vat` setting says which,
     * and this works backwards from the gross when it has to.
     *
     * **Payables carry no tax here.** A bill from a supplier arrives with
     * whatever tax that supplier charged, already on the document; computing
     * our own VAT onto it would be inventing a figure for somebody else's
     * paperwork. So the amount stands as given, and the breakdown is flat.
     */
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

        // Inclusive: the figure already contains the VAT, so the net is what
        // is left once it is taken back out. Integer arithmetic throughout —
        // `intdiv` after multiplying, never a float multiplication on money.
        $inclusive = $this->rates->pricesIncludeVat();

        if ($inclusive && $vatRate > 0) {
            $net = intdiv($amountCents * 10_000, 10_000 + $vatRate);
            // The remainder goes to VAT rather than being rounded away, so
            // net + vat is exactly the figure the customer was quoted.
            $vat = $amountCents - $net;
        } else {
            $net = $amountCents;
            $vat = intdiv($net * $vatRate, 10_000);
        }

        /**
         * Withholding is on the **gross**, VAT included.
         *
         * That is how the BIR computes it, and it is the detail most worth
         * getting right: applying 2% to the net instead understates the
         * deduction on every invoice the business ever raises, and the error
         * only shows up when a customer pays less than expected.
         */
        $withholdingRate = $customer?->withholdsTax() === true ? $this->withholdingRateBp($customer) : 0;
        $withholding = intdiv(($net + $vat) * $withholdingRate, 10_000);

        return new TaxBreakdown(
            net_cents: $net,
            vat_cents: $vat,
            withholding_cents: $withholding,
            vat_rate_bp: $vatRate,
            withholding_rate_bp: $withholdingRate,
            treatment: $treatment,
        );
    }

    /** A breakdown with no tax on it — a payable, or an untaxed company. */
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

    /**
     * Does this sale carry VAT?
     *
     * Both halves have to agree. A zero-rated customer of a VAT-registered
     * company pays no VAT; so does any customer of a company that is not
     * VAT-registered. Either one alone is enough to answer no.
     */
    private function charges(VatTreatment $treatment): bool
    {
        return $treatment->charges() && $this->rates->chargesVat();
    }

    /** The company's own rate, or the statute's where it has not set one. */
    private function vatRateBp(): int
    {
        return $this->rates->vatRateBp();
    }

    /**
     * The customer's own rate, the firm's standing one, or the statute's.
     *
     * Three deep, and the order is the specificity: whether a particular
     * shipper withholds and at what is a fact about that shipper, so their
     * column wins; the company's setting is what to assume for the ones nobody
     * has recorded one against.
     */
    private function withholdingRateBp(Customer $customer): int
    {
        return $customer->withholding_rate_bp ?? $this->rates->withholdingRateBp();
    }
}
