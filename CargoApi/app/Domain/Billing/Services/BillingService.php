<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\InvoiceData;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Illuminate\Pagination\LengthAwarePaginator;

class BillingService
{
    public function __construct(private readonly InvoiceRepository $invoices) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->invoices->paginate($filters, $perPage);
    }

    public function create(InvoiceData $data): Invoice
    {
        return $this->invoices->create($data);
    }

    public function update(Invoice $invoice, InvoiceData $data): Invoice
    {
        return $this->invoices->update($invoice, $data);
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
     * Idempotent: settling an invoice that is already paid keeps the original
     * `paid_at`, so a second press does not move the date the money arrived.
     */
    public function settle(Invoice $invoice): Invoice
    {
        if ($invoice->isPaid()) {
            return $invoice;
        }

        $invoice->update([
            'status' => StatusValue::Paid->value,
            'paid_at' => now(),
        ]);

        return $invoice->refresh();
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

        return Invoice::create([
            'customer_id' => $trip->customer_id,
            'trip_id' => $trip->id,
            'issued_at' => $issued->toDateString(),
            'due_at' => $issued->copy()->addDays((int) config('cargo.billing.terms_days'))->toDateString(),
            'amount_cents' => $trip->price_cents,
            'currency' => $trip->currency,
            'direction' => InvoiceDirection::Receivable->value,
            'status' => StatusValue::Pending->value,
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
