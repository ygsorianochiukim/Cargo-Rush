<?php

declare(strict_types=1);

namespace App\Domain\Customer\Repositories;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class CustomerRepository extends Repository
{
    protected function model(): string
    {
        return Customer::class;
    }

    /**
     * Trip count and outstanding balance are the two numbers the list prints,
     * and both are aggregates — pulled here as subquery columns so a page of
     * customers is one query rather than one per row.
     *
     * The logins come along for the same reason: the list says which firms can
     * actually sign in, and reading that off the relation per row would be a
     * query per customer.
     */
    public function query(): Builder
    {
        return Customer::query()
            ->with('logins:id,customer_id,name,email')
            ->withCount('trips')
            ->withSum([
                'invoices as outstanding_cents' => fn ($q) => $q
                    ->where('direction', InvoiceDirection::Receivable->value)
                    ->whereIn('status', [StatusValue::Pending->value, StatusValue::Overdue->value]),
            ], 'amount_cents')
            ->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'contact'];
    }
}
