<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repositories;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class UserRepository extends Repository
{
    protected function model(): string
    {
        return User::class;
    }

    public function query(): Builder
    {
        return User::query()->with('driver:id,user_id,licence_no,licence_expiry,status')->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'email'];
    }

    public function findByEmail(string $email): ?User
    {
        return $this->query()->where('email', $email)->first();
    }
}
