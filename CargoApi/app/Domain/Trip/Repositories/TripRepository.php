<?php

declare(strict_types=1);

namespace App\Domain\Trip\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Every trip query in the system. Nothing else builds one.
 *
 * @extends Repository
 */
class TripRepository extends Repository
{
    protected function model(): string
    {
        return Trip::class;
    }

    /**
     * Trips always travel with the four names a row needs to render, so no
     * list view can fall into an N+1.
     */
    public function query(): Builder
    {
        return Trip::query()
            ->with([
                'customer:id,name', 'driver:id,name', 'helpers', 'vehicle:id,plate',
                // The partner and their unit, for the runs the company's own
                // crew is not on. Beside the driver rather than instead of it:
                // a trip has one or the other, and the board prints whichever
                // it has under a single "Handled by" column.
                'trucker:id,name,phone', 'truckerVehicle:id,plate',
                // The pre-trip check rides along because every list that shows a
                // run now says whether the unit was cleared before it rolled —
                // the driver's queue, the office board and the customer's own
                // deliveries. One eager load beats a query per row.
                'latestInspection.driver:id,name',
            ])
            ->orderByDesc('scheduled_at');
    }

    protected function searchable(): array
    {
        return ['reference', 'origin', 'destination', 'cargo'];
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

        /**
         * Who is moving it: the company's own crew, or a partner.
         *
         * A column rather than a status, because it cuts across every status —
         * the desk wants "everything a contractor is on", whether it is
         * confirmed, on the road or delivered. `company` is the absence of a
         * partner rather than the presence of a driver: a run booked before a
         * crew was named is still the company's work, and filtering on
         * `driver_id` would file it under neither.
         */
        if (! empty($filters['hauled_by'])) {
            $query->when(
                $filters['hauled_by'] === 'trucker',
                static fn (Builder $q): Builder => $q->whereNotNull('trucker_id'),
                static fn (Builder $q): Builder => $q->whereNull('trucker_id'),
            );
        }

        /** Where the work came from — the audit cut. See `BookingSource`. */
        if (! empty($filters['booking_source'])) {
            $query->where('booking_source', $filters['booking_source']);
        }

        if (! empty($filters['from'])) {
            $query->where('scheduled_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('scheduled_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }

    public function findByReference(string $reference): ?Trip
    {
        return $this->query()->where('reference', $reference)->first();
    }

    /** The one trip a driver is on right now, for the mobile dashboard. */
    /**
     * Scheduled work whose time has come.
     *
     * `user_id` is named in the eager load because the driver is loaded here
     * to be notified, and `query()` selects only id and name.
     */
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

    /**
     * Assigned or pending work that has not started, in the order the driver
     * will get to it.
     */
    public function queuedForDriver(string $driverId, array $statuses): Collection
    {
        return $this->query()
            ->reorder('scheduled_at')
            ->where('driver_id', $driverId)
            ->whereIn('status', $statuses)
            ->get();
    }

    /** Units with a live position, for the GPS Dashboard. */
    public function onTheRoad(): Collection
    {
        return $this->query()->with('latestPing')->onTheRoad()->get();
    }

    /**
     * Every run a crew member was on in a window, driver or helper.
     *
     * Both columns, because a helper was on the trip too — counting only the
     * driver's seat would show a helper's performance as a flat zero and make
     * the module useless for half the crew.
     *
     * Dated on `scheduled_at` rather than on the delivery, so a period's work
     * is the work that was booked into it. Dating it on delivery would move a
     * run into next month whenever it ran late, which is the opposite of what
     * somebody reviewing a month wants.
     *
     * `deliveryLog` comes along because on-time is the delivery's time
     * against the trip's ETA, and resolving that per trip would be an N+1
     * across a whole roster.
     *
     * @return Collection<int, Trip>
     */
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

    /**
     * @return array<string, int> status value => count
     *
     * Built on the bare model rather than `query()`: that one carries the
     * `orderByDesc('scheduled_at')` every list view wants, and a column the
     * `group by` does not name is exactly what `ONLY_FULL_GROUP_BY` refuses.
     */
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
