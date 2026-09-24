<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Delivery\Services\DeliveryService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Support\Geo;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A partner's own work: the jobs offered to them, and taking one.
 *
 * ## What a job is
 *
 * A `pending` trip **with this partner's name on it**. Not any pending trip —
 * there is no open board, and a request nobody has placed is the office's to
 * place. See `open()` for the argument; the short version is that a customer
 * who asked Cargo Rush to carry something asked *Cargo Rush*, and quietly
 * putting that load in front of a dozen contractors is not the deal they made.
 *
 * So this is still a view over the trip board the desk already works from, not
 * a new kind of record — just a much narrower one. A run the desk confirms in
 * the ordinary way disappears from it, with no cancellation anywhere, because
 * it stops being `pending`.
 *
 * ## The two ways a partner ends up on a run, and why they are paid differently
 *
 * **A customer chose them**, and they accepted (`accept`). The customer's money
 * is theirs to collect and the haulier's cut is charged to their wallet. The
 * run is marked `direct`.
 *
 * **The desk gave it to them** (`TruckerService::assign`). The haulier quoted,
 * invoices and collects; the partner is paid their share out of it. The run
 * stays `cargo_rush`, and there is nothing to accept — it goes straight to
 * `assigned` and appears on their own queue rather than here.
 *
 * Same percentage, opposite direction, and `booking_source` is what records
 * which happened. See `WalletService`.
 *
 * ## The lock on `accept()`
 *
 * Two partners can no longer race for the same load — an offer has one name on
 * it — but the row is still taken for update inside a transaction, and that is
 * not belt-and-braces. The desk can crew a run in-house, or release it, in the
 * same second a partner presses Accept on their phone. A check made before the
 * lock is a check made against a row that may have changed by the time the
 * write lands, and the loser has to be told cleanly rather than discovering it
 * at the pickup.
 */
class JobBoardService
{
    public function __construct(
        private readonly TripService $trips,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Work somebody has put this partner's name on, and nothing else.
     *
     * **There is no open board.** A run only reaches a trucker because somebody
     * chose them — a customer picking them off the hauler list, or the desk
     * handing it to them — and a request nobody has directed anywhere is not
     * theirs to see. It stays on the office's queue until a human decides where
     * it goes.
     *
     * That is a deliberate narrowing of what this used to be. The first version
     * put every unclaimed request in front of every vetted partner, first press
     * wins, which is how a ride-hailing app works and is not how freight is
     * booked: a customer who asked Cargo Rush to carry something has asked
     * *Cargo Rush*, and quietly offering that load to a dozen contractors is
     * not the deal they made.
     *
     * So what is here is one thing: **offers**. A request the customer held for
     * this partner by name, still `pending`, waiting on them to accept. Work
     * the desk assigned is not here either — that is the fleet exercising a
     * standing arrangement, so it goes straight to `assigned` and appears on
     * their own queue with nothing left to decide.
     *
     * Capacity is still checked, even though `PortalService::offerTo()` checked
     * it when the offer was made: a partner can put their only truck in the
     * shop between the two moments, and an offer they cannot physically take is
     * not one to show them.
     *
     * @return Collection<int, Trip>
     */
    public function open(Trucker $trucker, ?float $lat = null, ?float $lng = null): Collection
    {
        // Not vetted, or between trucks: an empty board rather than an error.
        // The app says why — an approval they are waiting on is a state to
        // explain, not a failure to report.
        $unit = $trucker->activeVehicle();

        if (! $trucker->isVetted() || $unit === null) {
            return new Collection;
        }

        $jobs = Trip::query()
            ->with(['customer:id,name', 'truckCategory:id,name'])
            ->where('status', StatusValue::Pending->value)
            // Never one of the fleet's own crew's.
            ->whereNull('driver_id')
            /**
             * Held for this partner by name, and no other row.
             *
             * The whole of the rule. An unclaimed request — `trucker_id` null —
             * is the office's to place and is invisible here; a request held for
             * somebody else is theirs and is equally invisible. A trucker's
             * board is what they have been offered, not a market.
             */
            ->where('trucker_id', $trucker->getKey())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->filter(static fn (Trip $trip): bool => $unit->canCarry($trip->weight_kg, $trip->truck_category_id));

        return $this->nearestFirst($jobs, $trucker, $lat, $lng);
    }

    /**
     * Take a job.
     *
     * The run becomes theirs and becomes actionable in one step: `assigned`,
     * which is the state a driver can leave on, because there is nothing left
     * for the desk to supply. That is the difference between this and the
     * desk's own confirmation — confirming exists to name a driver, a helper, a
     * unit and a time, and a partner accepting has just supplied all four for
     * themselves.
     */
    public function accept(Trucker $trucker, string $tripId): Trip
    {
        abort_unless(
            $trucker->isVetted(),
            403,
            'Your registration is still being reviewed. You will be able to take work once it is approved.',
        );

        $unit = $trucker->activeVehicle();

        abort_if($unit === null, 422, 'Add a truck to your profile before taking work.');
        abort_unless(
            $trucker->is_online,
            422,
            'Go online before taking work.',
        );

        return DB::transaction(function () use ($trucker, $tripId, $unit): Trip {
            /**
             * Locked, then checked.
             *
             * `lockForUpdate` holds the row for the rest of this transaction,
             * so the claim below is read against the row as it will be written
             * rather than as it was a moment ago. Two handsets pressing Accept
             * in the same second both reach this line; one waits, and reads the
             * other's write.
             */
            $trip = Trip::query()->lockForUpdate()->find($tripId);

            abort_if($trip === null, 404, 'That job is no longer on the board.');

            abort_unless(
                $trip->status === StatusValue::Pending,
                409,
                'Somebody has already taken that job.',
            );

            /**
             * Theirs to accept, or nothing.
             *
             * A partner may only accept a run somebody put their name on. An
             * unclaimed request is the office's to place — accepting one would
             * be helping yourself to work nobody offered — and a run held for
             * somebody else is plainly not theirs.
             *
             * Both answer 409 in the same words, deliberately: a caller holding
             * a trip id they were never offered learns nothing from the
             * difference between "that is not yours" and "that does not exist".
             */
            abort_unless(
                $trip->driver_id === null && $trip->trucker_id === $trucker->getKey(),
                409,
                'That job is not available to you.',
            );

            abort_unless(
                $unit->canCarry($trip->weight_kg, $trip->truck_category_id),
                422,
                'Your truck cannot carry that load.',
            );

            /**
             * Always `direct`, because everything that reaches here is a
             * customer's own choice.
             *
             * The only way a `pending` run carries a partner's name is
             * `PortalService::offerTo()` — a customer picking them off the
             * hauler list. Nobody at the desk quoted or agreed it, so the money
             * is between the two of them and the fleet takes its cut of a run
             * it never touched.
             *
             * The one path that produces `cargo_rush` is the desk assigning,
             * and it never reaches this method: an assigned run is already
             * `assigned`, which the status guard above refuses. Those two lines
             * are the whole of the money decision.
             */
            $trip->update([
                'trucker_id' => $trucker->getKey(),
                'trucker_vehicle_id' => $unit->getKey(),
                'booking_source' => BookingSource::Direct->value,
                'status' => StatusValue::Assigned->value,
            ]);

            $this->tellTheDesk($trip->refresh(), $trucker);

            return $trip;
        });
    }

    /**
     * Their own work — everything not yet closed out.
     *
     * @return Collection<int, Trip>
     */
    public function mine(Trucker $trucker): Collection
    {
        return Trip::query()
            ->with(['customer:id,name', 'truckerVehicle:id,plate'])
            ->where('trucker_id', $trucker->getKey())
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

    /**
     * The one they are on right now, if any.
     *
     * The mirror of `TripService::currentForDriver`. A partner can only be on
     * one run at a time, and the handset's home screen is built around that.
     */
    public function current(Trucker $trucker): ?Trip
    {
        return Trip::query()
            ->with(['customer:id,name', 'truckerVehicle:id,plate'])
            ->where('trucker_id', $trucker->getKey())
            ->where('status', StatusValue::InTransit->value)
            ->latest('updated_at')
            ->first();
    }

    /** What they have already closed out, newest first. */
    public function history(Trucker $trucker, int $limit = 50): Collection
    {
        return Trip::query()
            // The log too, so each finished run can say whether its photo
            // arrived — the Finished tab offers to send one when it did not.
            ->with(['customer:id,name', 'deliveryLog'])
            ->where('trucker_id', $trucker->getKey())
            ->whereIn('status', [StatusValue::Delivered->value, StatusValue::Cancelled->value])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Roll out.
     *
     * Deliberately not `TripService::startForDriver`, and the difference is one
     * line: no pre-trip check is demanded. That gate exists because a haulier
     * does not let its own unit leave the yard unexamined — it is the company
     * inspecting the company's truck. A partner's truck is not the company's to
     * clear, and a checklist against somebody else's maintenance would be a
     * record nobody could act on and a gate nobody could lift.
     *
     * Everything else is the same, including the dispatch record, because the
     * Dispatch Monitoring screen must not show a gap for a run that plainly
     * left.
     */
    public function start(Trucker $trucker, string $tripId, ?string $location = null): Trip
    {
        $trip = $this->ownedBy($trucker, $tripId);

        abort_unless(
            in_array($trip->status, [StatusValue::Assigned, StatusValue::Overdue], true),
            422,
            "That run cannot be started — it is {$trip->status->value}.",
        );

        // `dispatch()` answers with the dispatch record; the run is what the
        // handset needs back, re-read because the status moved under it.
        $this->trips->dispatch($trip, $location ?? $trip->origin);

        return $trip->refresh();
    }

    /**
     * Hand over, with the proof.
     *
     * Straight through to the ordinary completion, which is the point: the
     * delivery log, the proof photograph, the dispatch arrival and the
     * customer's invoice all happen exactly as they do for an employee. The
     * partner's wallet is credited by the same call, from inside the same
     * transaction — see `TripService::putOnTheBooks`.
     */
    public function deliver(Trucker $trucker, string $tripId, ProofData $proof): Trip
    {
        $trip = $this->ownedBy($trucker, $tripId);

        $delivered = $this->trips->complete($trip, $proof);

        $trucker->increment('trips_completed');

        return $delivered;
    }

    /**
     * A photograph sent after the hand-off, for a run already delivered.
     *
     * The gate with no signal: the run was closed with a typed name and no
     * picture, and the picture goes up later from somewhere with a mast. It
     * writes the evidence and nothing else — the money moved when the run
     * closed, and `DeliveryService::attachProof` will not move it twice.
     *
     * Only for delivered runs. One still on the road is handed over through
     * `deliver()`, which is the call that pays the partner.
     */
    public function attachProof(Trucker $trucker, string $tripId, ProofData $proof): Trip
    {
        $trip = $this->ownedBy($trucker, $tripId);

        abort_unless(
            $trip->status === StatusValue::Delivered && $trip->deliveryLog !== null,
            422,
            'Hand this run over first — a photo can be added once it is delivered.',
        );

        app(DeliveryService::class)->attachProof($trip->deliveryLog, $proof);

        return $trip->refresh()->load('deliveryLog');
    }

    /**
     * Their run, or a 404.
     *
     * Scoped to the partner rather than to the company, the same way every
     * driver endpoint is scoped to a `drivers` row: a partner holding a trip id
     * they were never given must get the same answer as one holding an id that
     * does not exist.
     */
    private function ownedBy(Trucker $trucker, string $tripId): Trip
    {
        $trip = Trip::query()
            ->where('trucker_id', $trucker->getKey())
            ->find($tripId);

        abort_if($trip === null, 404, 'That run is not yours.');

        return $trip;
    }

    /**
     * Sort by how far each load is from the partner, nearest first.
     *
     * Measured from the handset's position when it sent one, and from the
     * partner's last reported pin otherwise. A job with no origin pinned, or a
     * partner with no usable position, sorts last rather than first — an
     * unmeasurable distance is not a distance of zero, and treating it as one
     * would put every unpinned load at the top of every board.
     *
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
                // Hung on the model rather than returned alongside, so the
                // Resource can read it without the controller having to carry
                // a parallel array of distances that could fall out of step
                // with the rows.
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

    /** A partner taking a load is news at the desk: nobody there confirmed it. */
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
