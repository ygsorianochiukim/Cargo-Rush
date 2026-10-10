<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\TruckVerification;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TruckerRepository extends Repository
{
    protected function model(): string
    {
        return Trucker::class;
    }

    public function query(): Builder
    {
        return Trucker::query()->with(['vehicles', 'drivers'])->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'phone', 'licence_no'];
    }

    public function findByUser(int $userId): ?Trucker
    {
        return $this->query()->where('user_id', $userId)->first();
    }

    /** @return Collection<int, Trucker> */
    public function awaitingApproval(): Collection
    {
        return $this->query()->where('status', StatusValue::Pending->value)->get();
    }

    public function pendingCount(): int
    {
        return Trucker::query()->where('status', StatusValue::Pending->value)->count()
            + TruckerVehicle::query()->where('verification', TruckVerification::Pending->value)->count();
    }

    /** @return Collection<int, Trucker> */
    public function takingWork(): Collection
    {
        return $this->query()
            ->takingWork()
            ->orderByDesc('located_at')
            ->get()
            ->filter(static fn (Trucker $trucker): bool => $trucker->activeVehicle() !== null)
            ->values();
    }
}
