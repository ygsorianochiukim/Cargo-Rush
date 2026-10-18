<?php

declare(strict_types=1);

namespace App\Domain\Billing\Repositories;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Support\NumberOrder;
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
        return NumberOrder::newestFirst(Invoice::query()
            ->with(['customer:id,name', 'trip:id,reference'])
            ->withSum('allocations', 'amount_cents'), 'number')
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

    /** @return string[] */
    private function unsettled(): array
    {
        return [
            StatusValue::Pending->value,
            StatusValue::Partial->value,
            StatusValue::Overdue->value,
        ];
    }

    public function outstanding(InvoiceDirection $direction): int
    {
        return $this->outstandingInvoices($direction)
            ->sum(static fn (Invoice $invoice): int => $invoice->balanceCents());
    }

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

    public function collected(InvoiceDirection $direction): int
    {
        return (int) PaymentAllocation::query()
            ->whereHas('invoice', static fn (Builder $q) => $q->where('direction', $direction->value))
            ->sum('amount_cents');
    }

    public function settledBetween(InvoiceDirection $direction, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->settlementsBetween($direction, $from, $to)->sum('amount_cents');
    }

    /** @return Collection<int, PaymentAllocation> */
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

    public function vatRaisedBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Invoice::query()
            ->where('direction', InvoiceDirection::Receivable->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->sum('vat_cents');
    }

    public function overdueTotal(InvoiceDirection $direction): int
    {
        return $this->outstandingInvoices($direction)
            ->filter(static fn (Invoice $invoice): bool => $invoice->due_at !== null
                && $invoice->due_at->lt(today()))
            ->sum(static fn (Invoice $invoice): int => $invoice->balanceCents());
    }

    /** @return array{billed_cents: int, collected_cents: int} */
    public function collectionBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = Invoice::query()
            ->where('direction', InvoiceDirection::Receivable->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->withSum(
                ['allocations as collected_in_window' => static fn (Builder $query) => $query
                    ->whereHas('payment', static fn (Builder $payment) => $payment
                        ->whereDate('paid_on', '>=', $from->toDateString())
                        ->whereDate('paid_on', '<=', $to->toDateString()))],
                'amount_cents',
            )
            ->get();

        return [
            'billed_cents' => (int) $invoices->sum(static fn (Invoice $invoice): int => $invoice->dueCents()),
            'collected_cents' => (int) $invoices->sum(static fn (Invoice $invoice): int => min(
                $invoice->dueCents(),
                (int) ($invoice->collected_in_window ?? 0),
            )),
        ];
    }

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

    public function pendingPastDue(): Collection
    {
        return Invoice::query()
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Partial->value])
            ->whereDate('due_at', '<', now())
            ->get();
    }
}
