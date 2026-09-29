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
        $withholdingRate = $customer?->withholdsTax() === true ? $this->withholdingRateBp($customer) : 0;

        return $this->atRates($amountCents, $vatRate, $withholdingRate, $treatment);
    }

    /**
     * The same arithmetic, at rates the caller already holds.
     *
     * `on()` looks the rates up; this is for a document whose rates are
     * **frozen** on it. A re-quote of an old invoice must use what applied on
     * the day it was issued, not what the settings card says now, or repairing
     * one figure would quietly restate another.
     *
     * ## Rounding: half-up, to the centavo
     *
     * It used to be `intdiv`, which truncates — a VAT of ₱12.345 came out as
     * ₱12.34, and every calculator a customer checks the document against
     * says ₱12.35. Still integer arithmetic throughout: never a float
     * multiplication on money.
     */
    public function atRates(
        int $amountCents,
        int $vatRateBp,
        int $withholdingRateBp,
        VatTreatment $treatment = VatTreatment::Vatable,
    ): TaxBreakdown {
        // Inclusive: the figure already contains the VAT, so the net is what
        // is left once it is taken back out.
        $inclusive = $this->rates->pricesIncludeVat();

        if ($inclusive && $vatRateBp > 0) {
            $net = self::roundDiv($amountCents * 10_000, 10_000 + $vatRateBp);
            // The remainder goes to VAT rather than being rounded separately,
            // so net + vat is exactly the figure the customer was quoted.
            $vat = $amountCents - $net;
        } else {
            $net = $amountCents;
            $vat = self::roundDiv($net * $vatRateBp, 10_000);
        }

        /**
         * Withholding is on the **net** — the tax base, VAT excluded.
         *
         * The creditable withholding tax a customer keeps back is a percentage
         * of the income payment *exclusive of VAT*: the VAT on the same
         * invoice is the government's, passing through us, and not income of
         * ours to withhold on. It used to be taken on the gross, which
         * over-withheld by 12% of the rate on every document — the customer
         * remitted less than the BIR form they issued said, and the two never
         * agreed.
         */
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

    /**
     * Integer division rounded half away from zero — half-up on the positive
     * amounts every invoice carries, and symmetric on a negative adjustment so
     * a credit mirrors the charge it reverses.
     */
    public static function roundDiv(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;

        return $sign * intdiv(2 * abs($numerator) + abs($denominator), 2 * abs($denominator));
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
