<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Delivery\Services\DeliveryService;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Support\Geo;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerDriver;
use App\Domain\Trucker\Models\TruckerVehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class JobBoardService
{
    public function __construct(
        private readonly TripService $trips,
        private readonly NotificationService $notifications,
        private readonly InspectionService $inspections,
    ) {}

    /** @return Collection<int, Trip> */
    public function open(Trucker $trucker, ?float $lat = null, ?float $lng = null): Collection
    {
        if (! $trucker->isVetted() || $trucker->activeVehicle() === null) {
            return new Collection;
        }

        $jobs = Trip::query()
            ->with(['customer:id,name,vat_treatment,withholds_tax,withholding_rate_bp', 'truckCategory:id,name'])
            ->where('status', StatusValue::Pending->value)
            ->whereNull('driver_id')
            ->where('trucker_id', $trucker->getKey())
            ->whereNotNull('price_cents')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->filter(static fn (Trip $trip): bool => $trucker->fittingVehicle($trip->weight_kg, $trip->truck_category_id) !== null);

        return $this->nearestFirst($jobs, $trucker, $lat, $lng);
    }

    public function accept(Trucker $trucker, string $tripId): Trip
    {
        abort_unless(
            $trucker->isVetted(),
            403,
            'Your registration is still being reviewed. You will be able to take work once it is approved.',
        );

        abort_if(
            $trucker->activeVehicle() === null,
            422,
            'You need a truck on the road that Cargo Rush has verified before taking work.',
        );
        abort_unless(
            $trucker->is_online,
            422,
            'Go online before taking work.',
        );

        return DB::transaction(function () use ($trucker, $tripId): Trip {
            $trip = Trip::query()->lockForUpdate()->find($tripId);

            abort_if($trip === null, 404, 'That job is no longer on the board.');

            abort_unless(
                $trip->status === StatusValue::Pending,
                409,
                'Somebody has already taken that job.',
            );

            abort_unless(
                $trip->driver_id === null && $trip->trucker_id === $trucker->getKey(),
                409,
                'That job is not available to you.',
            );

            abort_unless(
                $trucker->fittingVehicle($trip->weight_kg, $trip->truck_category_id) !== null,
                422,
                'None of your trucks can carry that load.',
            );

            PricingService::mustBePriced($trip);

            $trip->update([
                'trucker_id' => $trucker->getKey(),
                'trucker_vehicle_id' => null,
                'vehicle_id' => null,
                'booking_source' => BookingSource::Direct->value,
                'status' => StatusValue::Assigned->value,
            ]);

            $this->tellTheDesk($trip->refresh(), $trucker);

            return $trip;
        });
    }

    /** @return Collection<int, Trip> */
    public function mine(Trucker $trucker, ?TruckerDriver $crew = null): Collection
    {
        return $this->scoped($trucker, $crew)
            ->with(['customer:id,name', 'truckerVehicle:id,plate', 'truckerDriver:id,name'])
            ->whereIn('status', [
                StatusValue::Assigned->value,
                StatusValue::InTransit->value,
                StatusValue::Scheduled->value,
                StatusValue::Overdue->value,
            ])
            ->orderBy('scheduled_at')
            ->orderBy('created_at')
            ->get();
    }

    public function current(Trucker $trucker, ?TruckerDriver $crew = null): ?Trip
    {
        return $this->scoped($trucker, $crew)
            ->with(['customer:id,name', 'truckerVehicle:id,plate', 'truckerDriver:id,name'])
            ->where('status', StatusValue::InTransit->value)
            ->latest('updated_at')
            ->first();
    }

    public function history(Trucker $trucker, int $limit = 50, ?TruckerDriver $crew = null): Collection
    {
        return $this->scoped($trucker, $crew)
            ->with(['customer:id,name', 'deliveryLog', 'truckerDriver:id,name'])
            ->whereIn('status', [StatusValue::Delivered->value, StatusValue::Cancelled->value])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function start(Trucker $trucker, string $tripId, ?string $location = null, ?TruckerDriver $crew = null): Trip
    {
        $trip = $this->ownedBy($trucker, $tripId, $crew);

        abort_unless(
            in_array($trip->status, [StatusValue::Assigned, StatusValue::Overdue], true),
            422,
            "That run cannot be started — it is {$trip->status->value}.",
        );

        $this->mustHaveATruck($trip);

        if ($crew !== null) {
            $this->mustBeCleared($trip, $crew);
        }

        abort_if(
            $crew === null && $trip->trucker_driver_id !== null,
            422,
            'This run is handed to one of your drivers. Take it back first if you are driving it yourself.',
        );

        $this->trips->dispatch($trip, $location ?? $trip->origin);

        return $trip->refresh();
    }

    public function deliver(Trucker $trucker, string $tripId, ProofData $proof, ?TruckerDriver $crew = null): Trip
    {
        $trip = $this->ownedBy($trucker, $tripId, $crew);

        $delivered = $this->trips->complete($trip, $proof);

        $trucker->increment('trips_completed');

        return $delivered;
    }

    public function attachProof(Trucker $trucker, string $tripId, ProofData $proof, ?TruckerDriver $crew = null): Trip
    {
        $trip = $this->ownedBy($trucker, $tripId, $crew);

        abort_unless(
            $trip->status === StatusValue::Delivered && $trip->deliveryLog !== null,
            422,
            'Hand this run over first — a photo can be added once it is delivered.',
        );

        app(DeliveryService::class)->attachProof($trip->deliveryLog, $proof);

        return $trip->refresh()->load('deliveryLog');
    }

    public function ownedBy(Trucker $trucker, string $tripId, ?TruckerDriver $crew = null): Trip
    {
        $trip = $this->scoped($trucker, $crew)->find($tripId);

        abort_if($trip === null, 404, 'That run is not yours.');

        return $trip;
    }

    private function mustHaveATruck(Trip $trip): void
    {
        if ($trip->trucker_vehicle_id !== null) {
            return;
        }

        $only = $this->onlyFittingTruck($trip);

        abort_if(
            $only === null,
            422,
            'Choose which truck takes this run first — tap Change on My Trips.',
        );

        $trip->update(['trucker_vehicle_id' => $only->getKey()]);
    }

    public function onlyFittingTruck(Trip $trip): ?TruckerVehicle
    {
        $fitting = $trip->trucker?->vehicles()->get()
            ->filter(static fn (TruckerVehicle $v): bool => $v->canCarry($trip->weight_kg, $trip->truck_category_id))
            ?? collect();

        return $fitting->count() === 1 ? $fitting->first() : null;
    }

    private function mustBeCleared(Trip $trip, TruckerDriver $crew): void
    {
        if (! config('cargo.inspection.required_before_start', true)) {
            return;
        }

        $clearance = $this->inspections->clearanceFor($trip);

        if (
            $clearance !== null
            && $clearance->trucker_vehicle_id === $trip->trucker_vehicle_id
            && $clearance->trucker_driver_id === $crew->getKey()
        ) {
            return;
        }

        abort_if(
            $clearance !== null,
            422,
            'The truck or the driver on this run changed since it was checked. Run the pre-trip check again on Inspect.',
        );

        $attempt = $trip->latestInspection;

        abort(422, $attempt === null
            ? 'Run the pre-trip check on Inspect before you leave — the truck has not been checked for this run.'
            : sprintf(
                'This truck is held on its pre-trip check: %s. Get it seen to, then check it again.',
                implode(', ', $attempt->failures()) ?: 'the checklist was not finished',
            ));
    }

    /** @return Builder<Trip> */
    private function scoped(Trucker $trucker, ?TruckerDriver $crew): Builder
    {
        return Trip::query()
            ->where('trucker_id', $trucker->getKey())
            ->when($crew !== null, static fn (Builder $query) => $query->where('trucker_driver_id', $crew->getKey()));
    }

    /**
     * @param  Collection<int, Trip>  $jobs
     * @return Collection<int, Trip>
     */
    private function nearestFirst(Collection $jobs, Trucker $trucker, ?float $lat, ?float $lng): Collection
    {
        $fromLat = $lat ?? ($trucker->hasFreshPosition() ? (float) $trucker->latitude : null);
        $fromLng = $lng ?? ($trucker->hasFreshPosition() ? (float) $trucker->longitude : null);

        if ($fromLat === null || $fromLng === null) {
            return $jobs->values();
        }

        return $jobs
            ->each(static function (Trip $trip) use ($fromLat, $fromLng): void {
                $trip->distance_from_m = $trip->origin_lat === null || $trip->origin_lng === null
                    ? null
                    : (int) round(Geo::metresBetween(
                        $fromLat,
                        $fromLng,
                        (float) $trip->origin_lat,
                        (float) $trip->origin_lng,
                    ));
            })
            ->sortBy(static fn (Trip $trip): int => $trip->distance_from_m ?? PHP_INT_MAX)
            ->values();
    }

    private function tellTheDesk(Trip $trip, Trucker $trucker): void
    {
        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Dispatcher],
            icon: 'fleet',
            title: 'Trucker took a job',
            detail: "{$trucker->name} took {$trip->reference} · {$trip->origin} → {$trip->destination}",
            tone: Tone::Info,
        );
    }
}
