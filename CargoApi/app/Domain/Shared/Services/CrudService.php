<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The plain five verbs, for the modules whose behaviour really is just CRUD.
 *
 * A module extends this and adds its own methods for anything that is not —
 * Trip, Fuel and Billing all do. The base is here so a module with no special
 * rules does not have to re-type five identical methods to get one.
 */
abstract class CrudService
{
    abstract protected function repository(): Repository;

    /**
     * @param  array<string, mixed>  $filters
     */
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
