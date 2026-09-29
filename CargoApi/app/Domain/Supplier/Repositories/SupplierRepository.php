<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class SupplierRepository extends Repository
{
    protected function model(): string
    {
        return Supplier::class;
    }

    /**
     * The list, with what has been spent at each one already on it.
     *
     * Three subquery sums rather than three queries per row, because the
     * interesting thing about a supplier is the total — a list of names and
     * phone numbers is an address book, and the office already has one of
     * those. The three are kept apart rather than added here so the screen can
     * say *what kind* of spend it was: a garage's total is servicing, a
     * chandler's is consumables, and reading one figure would hide that.
     *
     * Only **counted** expenses, matching `Expense::scopeCounted` — a pending
     * claim has not been approved and a cancelled one was refused, and neither
     * is money this supplier has had.
     */
    public function query(): Builder
    {
        return Supplier::query()
            ->with('category')
            ->withSum([
                'expenses as expense_spend_cents' => fn (Builder $q) => $q
                    ->where('status', StatusValue::Active->value),
            ], 'amount_cents')
            /**
             * Servicing that has actually cost something: jobs done and
             * posted to the sheet. `cost_cents` alone summed quotes on jobs
             * still booked, so a garage's total ran ahead of every figure
             * Finance had for it. `posted_cents` is what the sheet carries.
             */
            ->withSum([
                'maintenanceJobs as service_spend_cents' => fn (Builder $q) => $q
                    ->whereNotNull('completed_on')
                    ->where('posted_cents', '>', 0),
            ], 'posted_cents')
            // A cancelled bill charged the fleet nothing.
            ->withSum([
                'bills as billed_cents' => fn (Builder $q) => $q
                    ->where('status', '!=', StatusValue::Cancelled->value),
            ], 'amount_cents')
            ->withCount('expenses')
            ->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'contact', 'supplies'];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        // "Only the ones we still buy from", which is what a picker wants and
        // what the list wants when somebody is choosing rather than auditing.
        if (! empty($filters['active'])) {
            $query->active();
        }

        return $query;
    }
}
