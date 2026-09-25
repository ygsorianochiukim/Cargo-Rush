<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\InvoiceData;
use App\Domain\Billing\DTO\TaxBreakdown;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BillingService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TaxService $tax,
        private readonly PaymentService $payments,
        private readonly RateBook $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->invoices->paginate($filters, $perPage);
    }

    /**
     * Every matching document, unpaginated — for the CSV export.
     *
     * The one read here that is deliberately not a page. A spreadsheet of the
     * first 25 rows is not an export, and the filters are the caller's own, so
     * what comes back is the list they are looking at rather than the whole
     * table. It stays a `Collection` and not a cursor because these are one
     * office's invoices for a period, not a platform-wide sweep.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Invoice>
     */
    public function all(array $filters = []): Collection
    {
        return $this->invoices->all($filters);
    }

    /**
     * Raise a document by hand, with its tax worked out.
     *
     * The amount the form sends is the figure the desk has — the net haul, or
     * an all-in price, depending on `cargo.tax.prices_include_vat`. The VAT
     * and the withholding follow from the customer, and the rates that applied
     * are frozen on beside them.
     */
    public function create(InvoiceData $data): Invoice
    {
        $attributes = $data->persistable();

        return Invoice::create([
            ...$attributes,
            ...$this->taxFor($attributes)->columns(),
        ])->refresh();
    }

    /**
     * Edit one, recomputing the tax when the money or the customer moved.
     *
     * Only then: an edit that corrects a due date must not requote the tax,
     * because the rates may have changed since and the document would quietly
     * stop matching the copy the customer holds.
     *
     * ## The figure that comes in is the *base*, not the gross
     *
     * `amount_cents` on a write means what it means on a create — the figure
     * the desk has, which is the net unless the rate card is quoted all-in.
     * The tax is added to it. That is why the fallback below is
     * `taxBaseCents()` and not `amount_cents`: re-quoting an untouched invoice
     * from its own gross would add 12% to a figure that already contains 12%,
     * and an edit that changed nothing would raise the document by a seventh.
     * It did, on every save, until a form was given `taxable_base_cents` to
     * round-trip instead — see `Invoice::taxBaseCents()`.
     */
    public function update(Invoice $invoice, InvoiceData $data): Invoice
    {
        $attributes = $data->persistable();

        $rechargeable = $data->wasGiven('amount_cents')
            || $data->wasGiven('customer_id')
            || $data->wasGiven('direction');

        if ($rechargeable) {
            $attributes = [
                ...$attributes,
                ...$this->taxFor([
                    'amount_cents' => $attributes['amount_cents'] ?? $invoice->taxBaseCents(),
                    'customer_id' => $attributes['customer_id'] ?? $invoice->customer_id,
                    'direction' => $attributes['direction'] ?? $invoice->direction->value,
                ])->columns(),
            ];
        }

        $invoice->update($attributes);

        return $invoice->refresh();
    }

    /**
     * The tax on a set of invoice attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function taxFor(array $attributes): TaxBreakdown
    {
        $direction = $attributes['direction'] ?? InvoiceDirection::Receivable->value;

        return $this->tax->on(
            (int) ($attributes['amount_cents'] ?? 0),
            isset($attributes['customer_id']) ? Customer::find($attributes['customer_id']) : null,
            $direction instanceof InvoiceDirection ? $direction : InvoiceDirection::from((string) $direction),
        );
    }

    public function delete(Invoice $invoice): void
    {
        $this->invoices->delete($invoice);
    }

    /**
     * Settling is a verb, not a status a client gets to set directly.
     *
     * It writes `paid`, which used to be `delivered` — the word for a
     * closed-out haul, borrowed for want of a better one. Sharing a value with
     * deliveries meant no page could add up money that had actually arrived
     * without also counting every delivered trip, which is why the Dashboard
     * could show what was owed and never what was collected.
     *
     * **It now leaves a payment behind it.** It used to flip a status and
     * stamp a date, with nothing underneath — no amount, no method, no
     * reference to match a bank statement against. `PaymentService::settle`
     * records the balance as a real payment and derives the status from it,
     * so the one-click case still exists and no longer creates a document
     * marked paid by nothing.
     *
     * Idempotent: settling an invoice that is already settled returns it
     * untouched, so a second press does not move the date the money arrived.
     *
     * @param  array<string, mixed>  $attributes  Optional method, reference, date.
     */
    public function settle(Invoice $invoice, array $attributes = [], ?int $userId = null): Invoice
    {
        if ($invoice->balanceCents() <= 0) {
            return $invoice;
        }

        $this->payments->settle($invoice, $attributes, $userId);

        return $invoice->refresh();
    }

    /**
     * Receivables by how late they are.
     *
     * The report a collections call is made from, and the one the Billing page
     * could not produce: `totals()` says what is outstanding, and outstanding
     * money that is nine days late and outstanding money that is ninety are
     * different problems with different phone calls behind them.
     *
     * Buckets are counted from the **due date**, not the issue date, and each
     * invoice is counted at its **balance** rather than its face value — a
     * document half-paid is half a problem.
     *
     * @return array<string, mixed>
     */
    public function aging(InvoiceDirection $direction = InvoiceDirection::Receivable): array
    {
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $byCustomer = [];

        foreach ($this->invoices->outstandingInvoices($direction) as $invoice) {
            $balance = $invoice->balanceCents();

            if ($balance <= 0) {
                continue;
            }

            // Negative days means not yet due, which is the `current` bucket.
            $daysLate = (int) now()->startOfDay()->diffInDays($invoice->due_at, false) * -1;

            $bucket = match (true) {
                $daysLate <= 0 => 'current',
                $daysLate <= 30 => '1_30',
                $daysLate <= 60 => '31_60',
                $daysLate <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $balance;

            $name = $invoice->counterparty();

            $byCustomer[$name] ??= [
                'counterparty' => $name,
                'total_cents' => 0,
                ...array_fill_keys(array_keys($buckets), 0),
            ];

            $byCustomer[$name][$bucket] += $balance;
            $byCustomer[$name]['total_cents'] += $balance;
        }

        // Worst first: whoever owes the most is the call to make.
        usort($byCustomer, static fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);

        return [
            'buckets' => $buckets,
            'total_cents' => array_sum($buckets),
            'by_counterparty' => array_values($byCustomer),
            'currency' => 'PHP',
        ];
    }

    /**
     * Raise the receivable for a haul that has just been delivered.
     *
     * The billing form still exists for everything else — retainers, payables,
     * an adjustment somebody negotiated — but a delivery no longer waits for
     * anybody to remember it. The amount is the price the trip was quoted at
     * when it was booked, so the customer is invoiced what they were told,
     * and the terms come from configuration rather than from whoever typed
     * the due date.
     *
     * Three cases return null rather than a document, and each is a real one:
     *
     *  - **No customer.** The company's own freight. There is nobody to bill,
     *    and an invoice addressed to nobody is worse than none.
     *  - **No price.** A trip somebody explicitly zeroed. Billing zero pesos
     *    puts a document in a customer's history that asks for nothing.
     *  - **Already invoiced.** The existing document is returned untouched.
     *    Raising a second one for the same run is the failure this is here to
     *    prevent, and it is `trip_id` that makes it detectable at all.
     */
    public function raiseForTrip(Trip $trip): ?Invoice
    {
        if ($trip->customer_id === null || $trip->price_cents <= 0) {
            return null;
        }

        $existing = Invoice::where('trip_id', $trip->id)
            ->where('direction', InvoiceDirection::Receivable->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $issued = now();

        /**
         * The tax, worked out from the customer being billed.
         *
         * The trip's price is the figure the customer was quoted, and it is
         * the taxable base — or the gross containing the VAT, if the desk
         * quotes all-in. Either way `TaxService` decides, so a delivery's
         * invoice and one raised by hand carry the same arithmetic.
         */
        $tax = $this->tax->on($trip->price_cents, $trip->customer, InvoiceDirection::Receivable);

        return Invoice::create([
            'customer_id' => $trip->customer_id,
            'trip_id' => $trip->id,
            'issued_at' => $issued->toDateString(),
            'due_at' => $issued->copy()->addDays($this->rates->billingTermsDays())->toDateString(),
            'currency' => $trip->currency,
            'direction' => InvoiceDirection::Receivable->value,
            'status' => StatusValue::Pending->value,
            ...$tax->columns(),
        ]);
    }

    /**
     * Receivables and payables side by side — the two numbers the Billing
     * page leads with, plus what has actually been collected.
     *
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        $receivable = $this->invoices->outstanding(InvoiceDirection::Receivable);
        $payable = $this->invoices->outstanding(InvoiceDirection::Payable);

        return [
            'receivable_cents' => $receivable,
            'payable_cents' => $payable,
            // Positive means the business is owed more than it owes.
            'net_position_cents' => $receivable - $payable,
            // Money in, as against money merely billed.
            'collected_cents' => $this->invoices->collected(InvoiceDirection::Receivable),
            'currency' => 'PHP',
        ];
    }

    /**
     * Move pending invoices past their due date to `overdue`.
     *
     * The same reasoning as trips: the fact is derived from the clock, so
     * something has to walk the table for the stored status to stay honest.
     */
    public function reconcileOverdue(): int
    {
        $count = 0;

        foreach ($this->invoices->pendingPastDue() as $invoice) {
            $invoice->update(['status' => StatusValue::Overdue->value]);
            $count++;
        }

        return $count;
    }
}
