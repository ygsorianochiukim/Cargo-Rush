<?php

declare(strict_types=1);

namespace App\Domain\Shared\Repositories;

use App\Domain\Shared\DTO\Data;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Shared Eloquent plumbing for the per-module repositories.
 *
 * Every query a module runs lives in its repository — a Service composes
 * repositories and a Controller talks to Services only. Nothing outside this
 * layer touches a query builder.
 */
abstract class Repository
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** A fresh query with the module's default eager loads and ordering. */
    public function query(): Builder
    {
        return $this->model()::query();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->applyFilters($this->query(), $filters)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
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
        // Refreshed on the way out because `persistable()` deliberately omits
        // what the caller did not send, and those columns get their value from
        // the database default — which Eloquent does not put back on the
        // in-memory instance. Without this, a Resource reading `status->value`
        // on a freshly created row reads it off null.
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

    /**
     * Modules override this to declare which query parameters they honour.
     * The base understands the two every list shares.
     *
     * @param  array<string, mixed>  $filters
     */
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

    /**
     * Columns `?search=` scans. Empty means the module is not searchable.
     *
     * @return string[]
     */
    protected function searchable(): array
    {
        return [];
    }
}
