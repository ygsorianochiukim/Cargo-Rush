<?php

declare(strict_types=1);

namespace App\Domain\Hr\Repositories;

use App\Domain\Hr\Models\Applicant;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class ApplicantRepository extends Repository
{
    protected function model(): string
    {
        return Applicant::class;
    }

    public function query(): Builder
    {
        return Applicant::query()
            ->with('employee:id,employee_no,first_name,last_name')
            ->orderByDesc('applied_on')
            ->orderByDesc('created_at');
    }

    protected function searchable(): array
    {
        return ['first_name', 'last_name', 'position_applied', 'contact', 'email', 'source'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        unset($filters['status']);

        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['stage'])) {
            $query->whereIn('stage', (array) $filters['stage']);
        }

        if (! empty($filters['position'])) {
            $query->where('position_applied', 'like', '%'.$filters['position'].'%');
        }

        if (! empty($filters['open'])) {
            $query->open();
        }

        return $query;
    }

    public function openCount(): int
    {
        return Applicant::query()->open()->count();
    }

    /** @return array<string, int> */
    public function countsByStage(): array
    {
        return Applicant::query()
            ->selectRaw('stage, count(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }
}
