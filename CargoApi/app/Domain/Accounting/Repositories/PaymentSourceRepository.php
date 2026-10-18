<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Repositories;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PaymentSourceRepository extends Repository
{
    protected function model(): string
    {
        return PaymentSource::class;
    }

    public function query(): Builder
    {
        $kind = DB::getQueryGrammar()->wrap('payment_sources.kind');

        return PaymentSource::query()
            ->withSum([
                'expenses as expense_spend_cents' => fn (Builder $q) => $q
                    ->where('status', StatusValue::Active->value),
            ], 'amount_cents')
            ->withSum([
                'fuelRecords as fuel_spend_cents' => fn (Builder $q) => $q
                    ->where('status', StatusValue::Active->value),
            ], 'amount_cents')
            ->withSum([
                'deliveryLogs as trip_fuel_spend_cents' => fn (Builder $q) => $q
                    ->where('status', StatusValue::Delivered->value),
            ], 'fuel_cost_cents')
            ->orderByRaw("CASE WHEN {$kind} = 'cash' THEN 0 ELSE 1 END")
            ->orderBy('kind')
            ->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'provider', 'last_four'];
    }

    /** @param array<string, mixed> $filters */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['active'])) {
            $query->active();
        }

        if (! empty($filters['kind'])) {
            $query->whereIn('kind', (array) $filters['kind']);
        }

        return $query;
    }
}
