<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Company;

/**
 * The rates in force, and the only thing that knows where they come from.
 *
 * Every number here has two possible homes: a column on the company, set by an
 * administrator on the settings card, and a line in `config/cargo.php`, which
 * is the install's default. Null on the column means the second — and the
 * single most useful property of this class is that **nothing else has to
 * know that**. A caller asks what the per-kilometre rate is and gets an
 * integer; it never sees a null, never reads `config()`, and cannot be the one
 * place that forgot the fallback.
 *
 * That was the actual bug this replaces. The payment terms were read straight
 * out of configuration in `BillingService`, the VAT-inclusive flag in two
 * places that had each grown their own `config()` call — so "what does this
 * install charge" had several answers and a settings screen could only have
 * moved one of them.
 *
 * ## What is not here
 *
 * The rate card. A zone, a truck class and a diesel step are a **table**, with
 * its own screens and its own model, and this is a handful of scalars.
 *
 * Nor, any more, a fallback tariff. There used to be one — base, per-km,
 * per-kg and a minimum — answering for any run the card did not cover, and it
 * was retired when pricing became zone-only: a run the card misses is now left
 * unpriced for the office rather than charged a figure nobody published. The
 * company's four `tariff_*` columns are still in the table, unread, so a
 * firm's old figures are not lost.
 *
 * The payroll contributions. SSS, PhilHealth and Pag-IBIG are the government's
 * figures, identical for every firm on the platform, and a form that invited an
 * office to edit them would be a form inviting an office to get payroll wrong.
 * They stay in configuration, where a circular changes them once for everybody.
 *
 * ## Reading it
 *
 * `Tenant::company()` is resolved on every call rather than cached here: a
 * console command sweeping the install moves the company in force between
 * iterations, and a rate book holding the first one would price company four's
 * runs on company one's terms. The lookup is a single already-loaded model.
 */
class RateBook
{
    /**
     * A company to answer for instead of the one in force. See `for()`.
     */
    private ?Company $subject = null;

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * A copy of this rate book that answers for one named company.
     *
     * For the resource that serialises a company record: it has the row in its
     * hands and should describe *that* firm's rates rather than trust that the
     * tenant in force happens to be the same one. In a request it always is —
     * there is no id on any company route — and a serialiser that depended on
     * that being true forever is a serialiser waiting to be wrong.
     */
    public function for(?Company $company): self
    {
        $book = clone $this;
        $book->subject = $company;

        return $book;
    }

    /**
     * The haulier's cut of a partner trucker's run, in basis points.
     *
     * One number for everybody the firm hauls with. It used to be overridable
     * per partner, from a field on the partner's own screen, and it is not any
     * more — see the migration that dropped `truckers.commission_bp`.
     *
     * The column is not nullable and defaults to 1200, so this normally answers
     * off the row. The config fallback is for a company record older than the
     * column, which is the only way it can be null.
     */
    public function truckerCommissionBp(): int
    {
        return (int) ($this->company()?->trucker_commission_bp
            ?? config('cargo.truckers.commission_bp', 1200));
    }

    /** How many days a delivered run's invoice has before it is overdue. */
    public function billingTermsDays(): int
    {
        return (int) ($this->company()?->billing_terms_days ?? config('cargo.billing.terms_days', 30));
    }

    /** VAT on sales, in basis points. 1200 is the standard PH rate. */
    public function vatRateBp(): int
    {
        return (int) ($this->company()?->vat_rate_bp ?? config('cargo.tax.vat_rate_bp', 1200));
    }

    /**
     * Does this firm charge VAT at all?
     *
     * Defaults true for a company that has not said — which is the reading
     * `TaxService` has always had, and the safe one: a registered haulier that
     * silently stopped charging VAT would be under-collecting a tax it still
     * owes.
     */
    public function chargesVat(): bool
    {
        return (bool) ($this->company()?->vat_registered ?? true);
    }

    /**
     * Expanded withholding, in basis points, for a customer with no rate of
     * its own. `customers.withholding_rate_bp` beats this; see `TaxService`.
     */
    public function withholdingRateBp(): int
    {
        return (int) ($this->company()?->withholding_rate_bp
            ?? config('cargo.tax.withholding_rate_bp', 200));
    }

    /** Are quoted prices all-in, with the VAT already inside them? */
    public function pricesIncludeVat(): bool
    {
        return (bool) ($this->company()?->prices_include_vat
            ?? config('cargo.tax.prices_include_vat', false));
    }

    /** The currency every quote is in. One install, one currency. */
    public function currency(): string
    {
        return (string) config('cargo.currency', 'PHP');
    }

    /**
     * Everything in force, flat, for a settings screen to draw.
     *
     * The same shape as `defaults()` on purpose: a card renders one and labels
     * the other "the install default", and a difference in shape between the
     * two would be a difference the screen had to reconcile.
     *
     * @return array<string, mixed>
     */
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

    /**
     * What the install would answer for a company that has set nothing.
     *
     * Shown beside the form so an office can see what it is departing from —
     * and so "restore the defaults" is a button rather than a support call
     * asking what the numbers used to be.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Which of them this company has actually set, as the raw columns.
     *
     * A settings form needs the difference between "12%, because you chose 12%"
     * and "12%, because nobody has chosen anything" — they look identical in a
     * number field and mean different things when the install default moves.
     *
     * @return array<string, mixed>
     */
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
