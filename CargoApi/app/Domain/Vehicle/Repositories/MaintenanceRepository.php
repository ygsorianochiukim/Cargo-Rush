<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Repositories;

use App\Domain\Shared\Repositories\Repository;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MaintenanceRepository extends Repository
{
    protected function model(): string
    {
        return MaintenanceJob::class;
    }

    public function query(): Builder
    {
        return MaintenanceJob::query()
            ->with(['vehicle:id,plate,model,odometer_km', 'supplier:id,name'])
            ->orderByRaw('completed_on is null desc')
            ->orderByDesc('completed_on')
            ->orderBy('due_at');
    }

    protected function searchable(): array
    {
        return ['kind', 'reference', 'note'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['from'])) {
            $from = Carbon::parse($filters['from'])->toDateString();

            $query->where(fn (Builder $q) => $q
                ->whereDate('completed_on', '>=', $from)
                ->orWhere(fn (Builder $booked) => $booked
                    ->whereNull('completed_on')
                    ->whereDate('due_at', '>=', $from)));
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse($filters['to'])->toDateString();

            $query->where(fn (Builder $q) => $q
                ->whereDate('completed_on', '<=', $to)
                ->orWhere(fn (Builder $booked) => $booked
                    ->whereNull('completed_on')
                    ->whereDate('due_at', '<=', $to)));
        }

        if (array_key_exists('costed', $filters) && $filters['costed'] !== '') {
            $query->{filter_var($filters['costed'], FILTER_VALIDATE_BOOLEAN) ? 'whereNotNull' : 'whereNull'}('cost_cents');
        }

        return $query;
    }

    public function costedBetween(array $filters = []): int
    {
        return (int) $this->applyFilters(MaintenanceJob::query(), $filters)->sum('cost_cents');
    }
}
