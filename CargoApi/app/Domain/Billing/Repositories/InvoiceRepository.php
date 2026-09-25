<?php

declare(strict_types=1);

namespace App\Domain\Billing\Repositories;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class InvoiceRepository extends Repository
{
    protected function model(): string
    {
        return Invoice::class;
    }

    public function query(): Builder
    {
        // The trip rides along because the list prints its reference; without
        // it, a page of invoices raised by deliveries is a page of queries.
        // The trip rides along because the list prints its reference; the
        // allocation sum rides along because every row prints a balance, and
        // without it a page of invoices is a page of extra queries.
        return Invoice::query()
            ->with(['customer:id,name', 'trip:id,reference'])
            ->withSum('allocations', 'amount_cents')
            ->orderByDesc('issued_at');
    }

    protected function searchable(): array
    {
        return ['number', 'payee'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        return $query;
    }

    /**
     * The statuses that mean "still owed something".
     *
     * `partial` joins the other two now that an invoice can be paid in part.
     * Leaving it out would have made a half-paid document vanish from every
     * receivables figure the moment the first instalment landed — the money
     * still owed would simply stop being counted.
     *
     * @return string[]
     */
    private function unsettled(): array
    {
        return [
            StatusValue::Pending->value,
            StatusValue::Partial->value,
            StatusValue::Overdue->value,
        ];
    }

    /**
     * Unsettled money in one direction, in centavos.
     *
     * The **balance**, not the face value. Two things now sit between the
     * figure on a document and the figure still owed: anything already paid
     * against it, and the withholding tax the customer keeps back and remits
     * on our behalf. Summing `amount_cents` counted both as receivable, which
     * overstated what was coming — a half-paid invoice looked entirely unpaid.
     *
     * The unsettled rows are loaded rather than aggregated in SQL, because the
     * balance is arithmetic across two tables and the expression that does it
     * in one statement differs by engine. This set is bounded by what the
     * business is owed, which is a number it is trying to keep small.
     */
    public function outstanding(InvoiceDirection $direction): int
    {
        return $this->outstandingInvoices($direction)
            ->sum(static fn (Invoice $invoice): int => $invoice->balanceCents());
    }

    /**
     * The unsettled documents themselves, with what has been paid on each.
     *
     * `withSum` makes the allocation total one correlated subquery instead of
     * one query per invoice, which is what `Invoice::paidCents()` reads.
     */
    public function outstandingInvoices(InvoiceDirection $direction): Collection
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->withSum('allocations', 'amount_cents')
            ->where('direction', $direction->value)
            ->whereIn('status', $this->unsettled())
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Money actually received in one direction, in centavos.
     *
     * Summed from the **allocations**, not from invoices marked paid. The old
     * version added up the face value of settled documents, which was the only
     * thing available when settling was a status flip — and it counted a
     * document as fully collected the instant somebody pressed the button,
     * including the withholding tax that never arrives.
     *
     * Allocations are the money against documents. A payment received and not
     * yet applied is real money, but it is a credit on the customer's account
     * rather than something collected against a bill, and it is counted when
     * it is applied.
     */
    public function collected(InvoiceDirection $direction): int
    {
        return (int) PaymentAllocation::query()
            ->whereHas('invoice', static fn (Builder $q) => $q->where('direction', $direction->value))
            ->sum('amount_cents');
    }

    /**
     * Money that actually moved in one direction over a window, in centavos.
     *
     * `collected()` for a period, and dated by **when the payment was made**
     * rather than by when the document was raised. That is the whole point of
     * it: a bill dated June and settled in July is July's money leaving the
     * bank, and a quarter that claimed it in June would be describing a
     * payment that had not happened yet.
     *
     * Summed from the allocations, so a part-paid document contributes only
     * the part paid — which is the honest figure for "what did we actually
     * hand over", and the reason this is not `where status = paid`.
     */
    public function settledBetween(InvoiceDirection $direction, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->settlementsBetween($direction, $from, $to)->sum('amount_cents');
    }

    /**
     * The same money, payment by payment, each with the day it moved.
     *
     * For a report that buckets by date rather than taking one total — Sales
     * does, and a figure dropped into the wrong week there is worse than no
     * figure at all. Built from the same query as `settledBetween()` so the
     * total and the series cannot disagree about what counts.
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function settlementsBetween(InvoiceDirection $direction, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PaymentAllocation::query()
            ->with('payment:id,paid_on')
            ->whereHas('invoice', static fn (Builder $q) => $q->where('direction', $direction->value))
            ->whereHas('payment', static fn (Builder $q) => $q
                ->whereDate('paid_on', '>=', $from->toDateString())
                ->whereDate('paid_on', '<=', $to->toDateString()))
            ->get();
    }

    /**
     * The part of what is outstanding that is already late, in centavos.
     *
     * A subset of `outstanding`, not a fourth bucket — which is why the
     * dashboard carries it alongside rather than deducting it. Chasing money
     * is a different job from expecting it.
     */
    public function overdueTotal(InvoiceDirection $direction): int
    {
        return Invoice::query()
            ->withSum('allocations', 'amount_cents')
            ->where('direction', $direction->value)
            ->where('status', StatusValue::Overdue->value)
            ->get()
            ->sum(static fn (Invoice $invoice): int => $invoice->balanceCents());
    }

    /** How many documents sit in each status, for the dashboard counts. */
    public function countsByStatus(InvoiceDirection $direction): array
    {
        return Invoice::query()
            ->where('direction', $direction->value)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * Unsettled invoices whose due date has passed.
     *
     * Overdue is derived from the date, so the flip to `overdue` is a
     * reconciliation the service runs, not a status a client can post.
     *
     * Part-paid documents are included. A customer who sent half in March and
     * nothing since is late on the rest, and leaving `partial` out of this
     * sweep would quietly exempt exactly the invoices most worth chasing.
     */
    public function pendingPastDue(): Collection
    {
        return Invoice::query()
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Partial->value])
            ->whereDate('due_at', '<', now())
            ->get();
    }
}
