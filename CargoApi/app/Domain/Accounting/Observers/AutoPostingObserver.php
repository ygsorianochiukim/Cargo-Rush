<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Observers;

use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Keeps the books following the records, as they are saved.
 *
 * One observer for every model that posts itself — and for the few that do not
 * but change what another one posts: a maintenance job decides how much of a
 * sheet day's Maintenance is a garage's bill, a trip's frozen commission is the
 * fleet's income on a partner's run, an allocation is part of its payment.
 *
 * **After the commit**, not inside it. The records that post are written in
 * steps — a payment and then its allocations, a partner payout and then the
 * runs it settles — and the posting has to see the finished set. Syncing after
 * the commit sees exactly what the next reader will; and because `sync` is
 * idempotent, the several events a single save fires cost one posting and a
 * few no-ops.
 *
 * **A failure is reported, never thrown.** By the time this runs the record is
 * saved; failing the request for the books' sake would tell the user their
 * delivery did not go through when it did. `cargo:accounting-backfill` puts
 * anything missed right.
 */
class AutoPostingObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly AutoPostingService $posting) {}

    public function created(Model $model): void
    {
        $this->follow($model);
    }

    public function updated(Model $model): void
    {
        $this->follow($model);
    }

    public function deleted(Model $model): void
    {
        $this->follow($model);
    }

    public function restored(Model $model): void
    {
        $this->follow($model);
    }

    private function follow(Model $model): void
    {
        if (! $this->posting->enabled()) {
            return;
        }

        try {
            foreach ($this->affected($model) as $record) {
                $this->posting->sync($record);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The records whose postings this change can move.
     *
     * @return iterable<Model>
     */
    private function affected(Model $model): iterable
    {
        if (AutoPostingService::handles($model)) {
            yield $model;
        }

        $previous = $model->getPrevious();

        switch (true) {
            case $model instanceof LedgerEntry:
                // A day's crew pay is part of what a paid run over it counts.
                yield from $this->paidRunsCovering([$model->date, $previous['date'] ?? null]);
                break;

            case $model instanceof PaymentAllocation:
                yield from Payment::query()->withTrashed()
                    ->whereKey(array_filter([$model->payment_id, $previous['payment_id'] ?? null]))
                    ->get();
                break;

            case $model instanceof Invoice && $model->direction === InvoiceDirection::Payable:
                // Paying it moves an expense, and a job carrying it moves how
                // much of that day's Maintenance was cash.
                yield from $this->paymentsFor([$model->getKey()]);
                yield from $this->sheetDaysFor(MaintenanceJob::query()->where('invoice_id', $model->getKey())->get());
                break;

            case $model instanceof MaintenanceJob:
                yield from $this->paymentsFor([$model->invoice_id, $previous['invoice_id'] ?? null]);
                yield from $this->sheetDaysFor([$model], $previous['completed_on'] ?? null);
                break;

            case $model instanceof WalletEntry && $model->settled_by !== null:
                yield from WalletEntry::query()->whereKey($model->settled_by)->get();
                break;

            case $model instanceof Trip:
                if ($model->wasChanged(['commission_cents', 'trucker_id', 'deleted_at']) || $model->trashed() || ! $model->exists) {
                    yield from WalletEntry::query()->where('trip_id', $model->getKey())->get();
                }
                break;

            case $model instanceof ExpenseCategory:
                if ($model->wasChanged(['account_code', 'key', 'name'])) {
                    yield from Expense::query()->where('category_id', $model->getKey())->get();
                }
                break;
        }
    }

    /** @param  array<int, mixed>  $invoiceIds */
    private function paymentsFor(array $invoiceIds): iterable
    {
        $ids = array_values(array_filter($invoiceIds));

        if ($ids === []) {
            return [];
        }

        return Payment::query()
            ->whereIn('id', PaymentAllocation::query()->whereIn('invoice_id', $ids)->select('payment_id'))
            ->get();
    }

    /**
     * The sheet days a job's cost landed on — where it is now and, when its
     * date moved, where it was.
     *
     * @param  iterable<MaintenanceJob>  $jobs
     */
    private function sheetDaysFor(iterable $jobs, mixed $previousDate = null): iterable
    {
        foreach ($jobs as $job) {
            $truck = $job->vehicle_id === null ? null : Truck::query()->where('vehicle_id', $job->vehicle_id)->value('id');

            if ($truck === null) {
                continue;
            }

            foreach (array_filter([$job->completed_on, $previousDate]) as $date) {
                yield from LedgerEntry::query()
                    ->where('truck_id', $truck)
                    ->whereDate('date', Carbon::parse($date)->toDateString())
                    ->get();
            }
        }
    }

    /** @param  array<int, mixed>  $dates */
    private function paidRunsCovering(array $dates): iterable
    {
        foreach (array_unique(array_map(static fn ($d): string => Carbon::parse($d)->toDateString(), array_filter($dates))) as $day) {
            yield from PayRun::query()
                ->where('status', PayRun::PAID)
                ->whereDate('period_start', '<=', $day)
                ->whereDate('period_end', '>=', $day)
                ->get();
        }
    }
}
