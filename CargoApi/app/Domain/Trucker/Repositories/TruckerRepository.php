<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Trucker\Models\Trucker;
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
        // The units come with the row everywhere: the roster shows a plate, the
        // detail screen shows the list, and `canTakeWork()` reads the
        // collection. Eager here rather than at four call sites, one of which
        // would forget and quietly issue a query per partner.
        return Trucker::query()->with('vehicles')->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'phone', 'licence_no'];
    }

    public function findByUser(int $userId): ?Trucker
    {
        return $this->query()->where('user_id', $userId)->first();
    }

    /**
     * Registrations nobody has looked at yet — the badge on the office's
     * sidebar row, and the list the desk works down.
     *
     * @return Collection<int, Trucker>
     */
    public function awaitingApproval(): Collection
    {
        return $this->query()->where('status', StatusValue::Pending->value)->get();
    }

    /** How many are waiting. The badge itself. */
    public function pendingCount(): int
    {
        return Trucker::query()->where('status', StatusValue::Pending->value)->count();
    }

    /**
     * Vetted partners with their switch on, for the desk's assign dialog.
     *
     * Ordered by how recently they reported a position, which is the best
     * available proxy for who is actually holding the app right now — a partner
     * whose last pin was three days ago is online in the column and unreachable
     * in practice.
     *
     * @return Collection<int, Trucker>
     */
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
