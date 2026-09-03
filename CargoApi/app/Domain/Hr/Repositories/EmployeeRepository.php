<?php

declare(strict_types=1);

namespace App\Domain\Hr\Repositories;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class EmployeeRepository extends Repository
{
    protected function model(): string
    {
        return Employee::class;
    }

    /**
     * Employees travel with their driver row and their login, because the
     * roster shows whether each exists — a "no account" chip on every row is
     * the whole point of the column, and resolving it per row would be an N+1.
     */
    public function query(): Builder
    {
        return Employee::query()
            ->with(['driver:id,name,status', 'user:id,name,email,role', 'jobPosition.defaultRole:id,key,name'])
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    protected function searchable(): array
    {
        return ['first_name', 'last_name', 'employee_no', 'position', 'contact', 'email'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['position'])) {
            $query->where('position', 'like', '%'.$filters['position'].'%');
        }

        if (! empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        if (! empty($filters['employment_type'])) {
            $query->whereIn('employment_type', (array) $filters['employment_type']);
        }

        // "Who has no login yet" — the question the office asks when it is
        // handing out accounts, and the reason this filter exists at all.
        if (array_key_exists('has_account', $filters) && $filters['has_account'] !== null) {
            $filters['has_account']
                ? $query->whereNotNull('user_id')
                : $query->whereNull('user_id');
        }

        return $query;
    }

    /** The next payroll number, as "EMP-0007". */
    public function nextEmployeeNo(): string
    {
        // Counted from every row including the deleted ones: a number that has
        // been on a payslip must never be handed to a second person.
        $count = Employee::withTrashed()->count() + 1;

        do {
            $candidate = 'EMP-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $count++;
        } while (Employee::withTrashed()->where('employee_no', $candidate)->exists());

        return $candidate;
    }

    /** @return array<string, int> position => headcount, for the roster tiles. */
    public function countsByPosition(): array
    {
        return Employee::query()
            ->onStaff()
            ->selectRaw('position, count(*) as aggregate')
            ->groupBy('position')
            ->pluck('aggregate', 'position')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /** @return array<string, int> status value => count */
    public function countsByStatus(): array
    {
        return Employee::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }
}
