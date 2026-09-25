<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A receivable (money in) or a payable (money out). */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'number', 'customer_id', 'payee', 'issued_at', 'due_at',
        'amount_cents', 'currency', 'direction', 'status',
        'trip_id', 'paid_at',
        'net_amount_cents', 'vat_cents', 'withholding_cents',
        'vat_rate_bp', 'withholding_rate_bp', 'vat_treatment',
        // Payables only: who the bill is from, where the office keeps a
        // record of them. `payee` stays for the ones it does not.
        'supplier_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_at' => 'date',
            'amount_cents' => 'integer',
            'net_amount_cents' => 'integer',
            'vat_cents' => 'integer',
            'withholding_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'withholding_rate_bp' => 'integer',
            'vat_treatment' => VatTreatment::class,
            'paid_at' => 'datetime',
            'direction' => InvoiceDirection::class,
            'status' => StatusValue::class,
        ];
    }

    /**
     * An invoice is never inserted without its number.
     *
     * Same reasoning as a trip's reference: assigning it in a service leaves a
     * window where a row exists with no number, which the column forbids and
     * which would be a document nobody can quote in a payment. Doing it here
     * means no code path — controller, seeder, factory or console — can skip
     * it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            $invoice->number ??= static::nextNumber(
                $invoice->direction ?? InvoiceDirection::Receivable,
            );

            /**
             * And never without its taxable base.
             *
             * The same rule as the number, for the same reason: `BillingService`
             * works the tax out and writes all of it, but a factory, a seeder,
             * a console command or a test may build an invoice directly with
             * nothing but an amount — and a row with no `net_amount_cents` is
             * one no report can add up.
             *
             * The fallback is **untaxed**: net equals the amount, VAT is zero.
             * That is the truthful reading of a create that said nothing about
             * tax, and it is what every document raised before tax existed
             * already says. Inventing 12% for a caller who never mentioned it
             * would put a VAT line on somebody's manual adjustment.
             */
            $invoice->net_amount_cents ??= (int) $invoice->amount_cents - (int) $invoice->vat_cents;
        });
    }

    /**
     * The next number in the series for a direction.
     *
     * Two series, because the workbook keeps two: money owed to us is
     * `INV-{year}-####` and money we owe is `BILL-{year}-####`. Mixing them
     * would make a receivable and a payable share a run of numbers, and the
     * two are reconciled against different people.
     *
     * Scoped to the year, so the count restarts each January exactly as the
     * printed series does.
     */
    public static function nextNumber(InvoiceDirection $direction, ?int $year = null): string
    {
        $year ??= (int) now()->year;
        $stem = ($direction === InvoiceDirection::Payable ? 'BILL' : 'INV')."-{$year}-";

        // `withTrashed`, because the unique index still holds a soft-deleted
        // row's number — reissuing it would collide on insert.
        //
        // Ordered by length first: the suffix is padded to four, so past 9999
        // a plain string sort puts `-10000` before `-9999` and the series
        // would start handing out numbers it has already used.
        $last = static::withTrashed()
            ->where('number', 'like', $stem.'%')
            ->orderByRaw('LENGTH(number) DESC')
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : (int) substr((string) $last, strlen($stem)) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The haul this document is for, when there is one.
     *
     * Null on an invoice somebody raised by hand — a monthly retainer, a
     * payable to a supplier — which is most of what the Billing form is for.
     * Set on every receivable the system raises itself when a run is
     * delivered, which is what lets Billing print the trip reference beside
     * the amount instead of leaving the reconciliation to a human.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** The payments that have been put against this document. */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations')
            ->withPivot('amount_cents')
            ->withTimestamps();
    }

    /**
     * What the payer actually has to remit.
     *
     * The gross less anything they keep back as withholding tax. This — not
     * `amount_cents` — is the figure a payment is measured against, and the
     * distinction is the reason invoices used to look short-paid: a customer
     * withholding 2% pays less than the document says, on purpose, and the
     * difference is remitted to the BIR on our behalf rather than owed to us.
     */
    public function dueCents(): int
    {
        return max(0, $this->amount_cents - $this->withholding_cents);
    }

    /**
     * How much has been received against it.
     *
     * Summed from the allocations rather than stored. A `paid_amount` column
     * would be a total that can disagree with the payments beneath it, which
     * is the one thing this codebase refuses to keep anywhere else.
     *
     * `loadSum` where it is available, so a list of fifty invoices is one
     * query rather than fifty-one — the repository eager-loads it.
     */
    public function paidCents(): int
    {
        return (int) ($this->allocations_sum_amount_cents ?? $this->allocations()->sum('amount_cents'));
    }

    /** Still outstanding. Zero on a document that has been settled. */
    public function balanceCents(): int
    {
        return max(0, $this->dueCents() - $this->paidCents());
    }

    /**
     * The figure the tax was worked out from — and the one a form edits.
     *
     * This exists because of a bug worth remembering. A write's `amount_cents`
     * means *the figure the desk has*: the net haul, or the all-in price when
     * the firm quotes VAT-inclusive. A read's `amount_cents` means net
     * plus VAT. One name, two meanings — and the edit form read the second and
     * posted it back as the first, so saving an invoice without changing
     * anything put 12% on top of a figure that already had 12% in it. Twice
     * through and a ₱7,530 haul was billed at ₱9,445.63.
     *
     * So the base is a thing the API can name and a form can round-trip.
     * `BillingService` re-quotes from this rather than from the gross, and
     * `InvoiceResource` sends it as `taxable_base_cents`.
     */
    public function taxBaseCents(): int
    {
        return app(RateBook::class)->pricesIncludeVat()
            ? (int) $this->amount_cents
            : (int) $this->net_amount_cents;
    }

    /** Settled. Money in, not merely billed. */
    public function isPaid(): bool
    {
        return $this->status === StatusValue::Paid;
    }

    /**
     * Paid in part, but not in full.
     *
     * A state the system could not previously express: settling was one flip
     * from pending to paid, so ₱50,000 against a ₱120,000 invoice had to be
     * recorded as one or the other, and both were wrong.
     */
    public function isPartlyPaid(): bool
    {
        $paid = $this->paidCents();

        return $paid > 0 && $paid < $this->dueCents();
    }

    /**
     * What the status *should* be, given the money against it.
     *
     * Derived here and written by `PaymentService`, which is the same
     * arrangement `trips` and the overdue sweep already use: the fact comes
     * from the data, and something walks the table to keep the stored copy
     * honest so that lists can filter and index on it.
     */
    public function statusFromPayments(): StatusValue
    {
        if ($this->balanceCents() <= 0) {
            return StatusValue::Paid;
        }

        if ($this->isPartlyPaid()) {
            return StatusValue::Partial;
        }

        return $this->due_at->isPast() ? StatusValue::Overdue : StatusValue::Pending;
    }

    /** Who the document is addressed to, whichever direction it points. */
    public function counterparty(): string
    {
        return $this->customer?->name ?? $this->payee ?? 'Unknown';
    }

    /** Unpaid and past its due date. Derived, so it cannot go stale. */
    public function isOverdue(): bool
    {
        return $this->status === StatusValue::Pending && $this->due_at->isPast();
    }
}
