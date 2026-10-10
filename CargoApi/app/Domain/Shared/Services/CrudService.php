<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class CrudService
{
    abstract protected function repository(): Repository;

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->repository()->paginate($filters, $perPage);
    }

    public function find(string|int $id): Model
    {
        return $this->repository()->findOrFail($id);
    }

    public function create(Data $data): Model
    {
        return $this->repository()->create($data);
    }

    public function update(Model $model, Data $data): Model
    {
        return $this->repository()->update($model, $data);
    }

    public function delete(Model $model): void
    {
        $this->repository()->delete($model);
    }
}
