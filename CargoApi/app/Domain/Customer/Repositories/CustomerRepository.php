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
