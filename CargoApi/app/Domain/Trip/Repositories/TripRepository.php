<?php

declare(strict_types=1);

namespace App\Domain\Trip\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** @extends Repository */
class TripRepository extends Repository
{
    protected function model(): string
    {
        return Trip::class;
    }

    public function query(): Builder
    {
        return Trip::query()
            ->with([
                'customer:id,name', 'driver:id,name', 'helpers', 'vehicle:id,plate',
                'trucker:id,name,business_name,phone', 'truckerVehicle:id,plate',
                'truckerDriver:id,name',
                'pricingZone:id,name',
                'latestInspection.driver:id,name',
                'invoice' => static fn ($query) => $query->withSum('allocations', 'amount_cents'),
            ])
            ->orderByDesc('scheduled_at');
    }

    protected function searchable(): array
    {
        return ['reference', 'legacy_reference', 'origin', 'destination', 'cargo'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['driver_id'])) {
            $query->where(function (Builder $q) use ($filters): void {
                $q->where('driver_id', $filters['driver_id'])
                    ->orWhereHas('helpers', fn (Builder $h) => $h->whereKey($filters['driver_id']));
            });
        }

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['trucker_id'])) {
            $query->where('trucker_id', $filters['trucker_id']);
        }

        if (! empty($filters['hauled_by'])) {
            $query->when(
                $filters['hauled_by'] === 'trucker',
                static fn (Builder $q): Builder => $q->whereNotNull('trucker_id'),
                static fn (Builder $q): Builder => $q->whereNull('trucker_id'),
            );
        }

        if (! empty($filters['booking_source'])) {
            $query->where('booking_source', $filters['booking_source']);
        }

        if (! empty($filters['from'])) {
            $query->where('scheduled_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('scheduled_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->reorder()
            ->orderByRaw('CASE WHEN reference_seq IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('reference_seq')
            ->orderByDesc('created_at');
    }

    public function findByReference(string $reference): ?Trip
    {
        return $this->query()->where('reference', $reference)->first()
            ?? $this->query()->where('legacy_reference', $reference)->first();
    }

    public function dueScheduled(): Collection
    {
        return Trip::query()
            ->with('driver:id,name,user_id')
            ->where('status', StatusValue::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();
    }

    public function currentForDriver(string $driverId): ?Trip
    {
        return $this->query()
            ->with('latestPing')
            ->where('driver_id', $driverId)
            ->where('status', StatusValue::InTransit->value)
            ->orderBy('scheduled_at')
            ->first();
    }

    public function queuedForDriver(string $driverId, array $statuses): Collection
    {
        return $this->query()
            ->reorder('scheduled_at')
            ->where('driver_id', $driverId)
            ->whereIn('status', $statuses)
            ->get();
    }

    public function onTheRoad(): Collection
    {
        return $this->query()->with('latestPing')->onTheRoad()->get();
    }

    /** @return Collection<int, Trip> */
    public function forCrewBetween(string $driverId, Carbon $from, Carbon $to): Collection
    {
        return Trip::query()
            ->with('deliveryLog')
            ->where(function (Builder $query) use ($driverId): void {
                $query->where('driver_id', $driverId)
                    ->orWhereHas('helpers', fn (Builder $h) => $h->whereKey($driverId));
            })
            ->whereBetween('scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();
    }

    /** @return array<string, int> */
    public function countsByStatus(?string $customerId = null): array
    {
        return Trip::query()
            ->when($customerId !== null, static fn (Builder $query) => $query->where('customer_id', $customerId))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }
}
