<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A statement of account: what one firm owed, what moved, and what is left.
 *
 * The document a collections call is made from, and the one a customer's
 * accounts department asks for when the invoices and their own records have
 * stopped agreeing. It is not a list of invoices — the billing page is already
 * that. It is a **running account**: an opening balance, every document and
 * every payment in date order, and a closing balance that follows from them.
 *
 * ## Why the running balance matters
 *
 * An aging report says ₱48,000 is 30 days late. A statement says *how it got
 * there*: this invoice, that part payment, this credit. When a customer
 * disputes a figure, the disagreement is always about one line — and a
 * statement is the only view that puts both sides' lines in the same order so
 * the argument can be about a fact rather than a total.
 *
 * ## Both directions
 *
 * The same report serves a **customer** (what they owe us) and a **supplier**
 * (what we owe them), because a statement of account is symmetrical: one
 * party's receivable is the other's payable. `direction` decides which set of
 * documents is read, and the words on the page follow from it — a supplier
 * statement that said "amount due from" would be read the wrong way round by
 * whoever is paying it.
 *
 * ## Opening balance
 *
 * Everything before the range, netted: documents raised less money received.
 * Without it a statement for March would open at zero and close short by
 * whatever February left owing, which is the error that makes a statement worth
 * less than the invoices it was built from.
 */
class StatementOfAccountService
{
    /**
     * Within one day: the document, then the withholding kept back on it,
     * then any money received.
     */
    private const KIND_ORDER = ['invoice' => 0, 'withholding' => 1, 'payment' => 2];

    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    /**
     * One customer's account over a period.
     *
     * @return array<string, mixed>
     */
    public function forCustomer(
        Customer $customer,
        ?Carbon $from = null,
        ?Carbon $to = null,
        InvoiceDirection $direction = InvoiceDirection::Receivable,
    ): array {
        $to ??= Carbon::now();

        $opening = $this->openingBalance($customer, $from, $direction);

        $lines = $this->lines($customer, $from, $to, $direction)
            /**
             * Date first, then the document before the payment that settled it:
             * a statement showing a receipt above the invoice it paid would read
             * as a credit out of nowhere.
             *
             * One composite key rather than `sortBy([...])` with three
             * closures. That form treats a callable as a *comparator* taking
             * both rows, not as a key extractor — so three innocent-looking
             * one-argument closures silently ordered the page by whatever the
             * first one's return value cast to.
             */
            ->sortBy(fn (array $line): string => sprintf(
                '%s|%d|%s',
                (string) $line['date'],
                self::KIND_ORDER[$line['kind']] ?? 9,
                (string) $line['reference'],
            ))
            ->values();

        $running = $opening;
        $charges = 0;
        $credits = 0;

        $rows = $lines->map(function (array $line) use (&$running, &$charges, &$credits): array {
            $charges += $line['charge_cents'];
            $credits += $line['credit_cents'];
            $running += $line['charge_cents'] - $line['credit_cents'];

            return [...$line, 'balance_cents' => $running];
        })->all();

        $company = $this->tenant->company();

        return [
            /**
             * Whose statement this is — and who is sending it.
             *
             * A statement that leaves the building needs both, for the reason a
             * printed invoice does: it is filed by somebody with a drawer full
             * of them from other hauliers.
             */
            'issuer' => [
                'name' => $company?->name,
                'tin' => $company?->tin,
                'address' => $company?->address,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                'logo_url' => $this->logos->url($company?->logo_path),
            ],

            'account' => [
                'id' => $customer->getKey(),
                'name' => $customer->name,
                'contact' => $customer->contact,
                'address' => $customer->address,
                'tin' => $customer->tin,
            ],

            'direction' => $direction->value,
            /**
             * What to print at the top, and which way the balance reads.
             *
             * A receivable statement is money we are owed; a payable one is
             * money we owe. The heading has to say which, because the figure at
             * the bottom is the same number with the opposite meaning.
             */
            'title' => $direction === InvoiceDirection::Receivable
                ? 'Statement of Account'
                : 'Supplier Statement',
            'balance_label' => $direction === InvoiceDirection::Receivable
                ? 'Amount due from this account'
                : 'Amount we owe this account',

            'range' => ['from' => $from?->toDateString(), 'to' => $to->toDateString()],

            'opening_balance_cents' => $opening,
            'charges_cents' => $charges,
            'credits_cents' => $credits,
            'closing_balance_cents' => $running,
            'closing_in_words' => Money::inWords($running),

            'lines' => $rows,

            /**
             * How late the closing balance is, by document.
             *
             * The collections half of the page: a balance is a number to chase,
             * and how long it has been outstanding is what decides whether the
             * call is a reminder or a conversation.
             */
            'aging' => $this->aging($customer, $to, $direction),

            'currency' => 'PHP',
        ];
    }

    /**
     * Everything before the range, netted into one figure.
     *
     * Documents raised less money received. Null range means the statement
     * covers everything, so there is nothing before it and the opening is zero.
     */
    private function openingBalance(
        Customer $customer,
        ?Carbon $from,
        InvoiceDirection $direction,
    ): int {
        if ($from === null) {
            return 0;
        }

        // What was due, not the face value: the withholding on each earlier
        // document is a credit line of its own on the day it was raised, so
        // it is already behind the opening balance too.
        $billed = (int) Invoice::query()
            ->where('customer_id', $customer->getKey())
            ->where('direction', $direction->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '<', $from)
            ->sum(DB::raw('amount_cents - withholding_cents'));

        $received = (int) PaymentAllocation::query()
            ->whereHas('invoice', fn ($query) => $query
                ->where('customer_id', $customer->getKey())
                ->where('direction', $direction->value))
            ->whereHas('payment', fn ($query) => $query->whereDate('paid_on', '<', $from))
            ->sum('amount_cents');

        return $billed - $received;
    }

    /**
     * The documents and the payments, as statement lines.
     *
     * Two queries rather than a union: they are different things with different
     * dates — an invoice is dated when it was issued and a payment when the
     * money arrived — and pretending otherwise is how a statement ends up
     * ordered by the wrong column.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(
        Customer $customer,
        ?Carbon $from,
        Carbon $to,
        InvoiceDirection $direction,
    ): Collection {
        $invoices = Invoice::query()
            ->with('trip:id,reference')
            ->where('customer_id', $customer->getKey())
            ->where('direction', $direction->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->when($from !== null, fn ($query) => $query->whereDate('issued_at', '>=', $from))
            ->whereDate('issued_at', '<=', $to)
            ->get()
            ->flatMap(fn (Invoice $invoice): array => array_values(array_filter([
                [
                    'date' => $invoice->issued_at?->toDateString(),
                    'kind' => 'invoice',
                    'reference' => $invoice->number,
                    'detail' => $invoice->trip?->reference === null
                        ? 'Invoice'
                        : 'Invoice · '.$invoice->trip->reference,
                    'invoice_id' => $invoice->getKey(),
                    'due_at' => $invoice->due_at?->toDateString(),
                    // The document's face value, so the line agrees with the
                    // invoice the customer holds.
                    'charge_cents' => (int) $invoice->amount_cents,
                    'credit_cents' => 0,
                ],
                /**
                 * The withholding, as its own credit on the same day.
                 *
                 * The customer keeps it back and remits it to the BIR for us,
                 * so it is never going to arrive as a payment — and payments
                 * are capped at the due, gross less withholding. Charging the
                 * gross with nothing to offset the withheld part left every
                 * withholding customer's statement open by exactly that much,
                 * forever. Shown rather than netted into the charge, so the
                 * charge still matches the document and the 2307 the customer
                 * issues has a line to be ticked against.
                 */
                $invoice->withholding_cents > 0 ? [
                    'date' => $invoice->issued_at?->toDateString(),
                    'kind' => 'withholding',
                    'reference' => $invoice->number,
                    'detail' => sprintf(
                        'Withholding tax%s · kept back on %s',
                        $invoice->withholding_rate_bp > 0
                            ? ' ('.rtrim(rtrim(number_format($invoice->withholding_rate_bp / 100, 2), '0'), '.').'%)'
                            : '',
                        $invoice->number,
                    ),
                    'invoice_id' => $invoice->getKey(),
                    'due_at' => null,
                    'charge_cents' => 0,
                    'credit_cents' => (int) $invoice->withholding_cents,
                ] : null,
            ])));

        $payments = PaymentAllocation::query()
            ->with(['payment', 'invoice:id,number'])
            ->whereHas('invoice', fn ($query) => $query
                ->where('customer_id', $customer->getKey())
                ->where('direction', $direction->value))
            ->whereHas('payment', fn ($query) => $query
                ->when($from !== null, fn ($inner) => $inner->whereDate('paid_on', '>=', $from))
                ->whereDate('paid_on', '<=', $to))
            ->get()
            ->map(fn (PaymentAllocation $allocation): array => [
                'date' => $allocation->payment?->paid_on?->toDateString(),
                'kind' => 'payment',
                'reference' => $allocation->payment?->reference ?? 'Payment',
                'detail' => trim(sprintf(
                    '%s · against %s',
                    ucfirst(str_replace('_', ' ', (string) ($allocation->payment?->method ?? 'payment'))),
                    $allocation->invoice?->number ?? 'account',
                )),
                'invoice_id' => $allocation->invoice_id,
                'due_at' => null,
                'charge_cents' => 0,
                'credit_cents' => (int) $allocation->amount_cents,
            ]);

        return $invoices->concat($payments);
    }

    /**
     * The closing balance, split by how late each document is.
     *
     * The buckets a collections desk works in: not yet due, and then thirty-day
     * steps past it. Measured per document from its own due date, because "60
     * days" means sixty days past due and not sixty days since it was raised.
     *
     * @return array<string, mixed>
     */
    private function aging(Customer $customer, Carbon $asOf, InvoiceDirection $direction): array
    {
        $buckets = ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0];

        /**
         * Paid **as at the statement's date**, not as at today.
         *
         * A statement to the end of June aged against every payment to date
         * showed a July receipt as already clearing a June balance — so the
         * aging disagreed with the closing balance above it, which counts only
         * money received by the 30th.
         */
        $open = Invoice::query()
            ->withSum(
                ['allocations as paid_by_then' => static fn ($query) => $query
                    ->whereHas('payment', static fn ($payment) => $payment->whereDate('paid_on', '<=', $asOf))],
                'amount_cents',
            )
            ->where('customer_id', $customer->getKey())
            ->where('direction', $direction->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '<=', $asOf)
            ->get();

        foreach ($open as $invoice) {
            // Not `balanceCents()`: with nothing paid by then the sum is null,
            // and that method would fall back to every payment to date.
            $balance = max(0, $invoice->dueCents() - (int) ($invoice->paid_by_then ?? 0));

            if ($balance <= 0) {
                continue;
            }

            $due = $invoice->due_at;
            $late = $due === null ? 0 : (int) $due->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);

            $bucket = match (true) {
                $late <= 0 => 'current',
                $late <= 30 => 'days_1_30',
                $late <= 60 => 'days_31_60',
                $late <= 90 => 'days_61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $balance;
        }

        return [...$buckets, 'total_cents' => array_sum($buckets)];
    }
}
