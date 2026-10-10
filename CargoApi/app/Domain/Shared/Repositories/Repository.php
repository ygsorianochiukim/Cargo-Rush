<?php

declare(strict_types=1);

namespace App\Domain\Shared\Repositories;

use App\Domain\Shared\DTO\Data;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Repository
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    public function query(): Builder
    {
        return $this->model()::query();
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->applyFilters($this->query(), $filters)->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    public function all(array $filters = []): Collection
    {
        return $this->applyFilters($this->query(), $filters)->get();
    }

    public function find(string|int $id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string|int $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    public function create(Data $data): Model
    {
        return $this->model()::create($data->persistable())->refresh();
    }

    public function update(Model $model, Data $data): Model
    {
        $model->update($data->persistable());

        return $model->refresh();
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    /** @param array<string, mixed> $filters */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (! empty($filters['search']) && $this->searchable() !== []) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term): void {
                foreach ($this->searchable() as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        return $query;
    }

    /** @return string[] */
    protected function searchable(): array
    {
        return [];
    }
}
