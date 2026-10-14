<?php

declare(strict_types=1);

namespace App\Domain\Driver\Repositories;

use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DriverRepository extends Repository
{
    protected function model(): string
    {
        return Driver::class;
    }

    public function query(): Builder
    {
        return Driver::query()->with('employee:id,driver_id,employee_no')->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'licence_no', 'contact'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        $role = $filters['role'] ?? CrewRole::Driver->value;

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        return $query;
    }

    public function findByUser(int $userId): ?Driver
    {
        return $this->query()->where('user_id', $userId)->first();
    }

    public function licencesExpiringWithin(int $days = 60): Collection
    {
        return $this->query()
            ->whereNotNull('licence_expiry')
            ->whereDate('licence_expiry', '<=', now()->addDays($days))
            ->get();
    }

    public function fleetOnTimeRate(): float
    {
        $row = Driver::query()
            ->where('role', CrewRole::Driver->value)
            ->selectRaw('sum(on_time_rate * trips_completed) as weighted, sum(trips_completed) as trips')
            ->first();

        $trips = (int) ($row->trips ?? 0);

        return $trips === 0 ? 0.0 : round(((float) $row->weighted) / $trips, 1);
    }
}
