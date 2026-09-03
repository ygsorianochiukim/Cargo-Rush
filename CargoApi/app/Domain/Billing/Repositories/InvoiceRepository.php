<?php

declare(strict_types=1);

namespace App\Domain\Billing\Repositories;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
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
        return Invoice::query()
            ->with(['customer:id,name', 'trip:id,reference'])
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

    /** Unsettled money in one direction, in centavos. */
    public function outstanding(InvoiceDirection $direction): int
    {
        return (int) Invoice::query()
            ->where('direction', $direction->value)
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Overdue->value])
            ->sum('amount_cents');
    }

    /**
     * Settled money in one direction, in centavos.
     *
     * The counterpart of `outstanding`, and the reason `paid` had to become a
     * status of its own: while settling wrote `delivered`, this query could
     * not be written at all without also picking up every trip's document.
     */
    public function collected(InvoiceDirection $direction): int
    {
        return (int) Invoice::query()
            ->where('direction', $direction->value)
            ->where('status', StatusValue::Paid->value)
            ->sum('amount_cents');
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
        return (int) Invoice::query()
            ->where('direction', $direction->value)
            ->where('status', StatusValue::Overdue->value)
            ->sum('amount_cents');
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
     * Pending invoices whose due date has passed.
     *
     * Overdue is derived from the date, so the flip to `overdue` is a
     * reconciliation the service runs, not a status a client can post.
     */
    public function pendingPastDue(): Collection
    {
        return Invoice::query()
            ->where('status', StatusValue::Pending->value)
            ->whereDate('due_at', '<', now())
            ->get();
    }
}
