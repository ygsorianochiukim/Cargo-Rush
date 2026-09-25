<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\PricingService;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Services\ProofStore;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use App\Domain\Trucker\Services\WalletService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Booking, confirming, amending and closing a trip.
 *
 * A trip is not just a row: dispatch and delivery records exist for its whole
 * life so the Dispatch and Delivery Logs modules never show a gap for a trip
 * that plainly exists. Creating those alongside it is this service's job.
 *
 * ## The states, and who owns each
 *
 * | Status       | Means                                | Who writes it |
 * | ------------ | ------------------------------------ | ------------- |
 * | `pending`    | Requested. Nobody is on it yet.      | Customer, or the office |
 * | `scheduled`  | Booked for a later day, crew named.  | Office |
 * | `assigned`   | Confirmed and actionable now.        | Office (`confirm`), or the clock |
 * | `in_transit` | On the road.                         | Driver (`start`) |
 * | `delivered`  | Handed over, with the proof.         | Driver (`deliver`) |
 *
 * `pending` used to mean "due and waiting for the driver", which left nothing
 * to call a request nobody had looked at yet. It now means exactly that — work
 * asking for a decision — and `assigned` carries what it used to: a run with a
 * driver, a helper, a unit and a time, which is the only state a driver can
 * leave on. That is the whole reason confirming is a step: a customer's
 * request has none of those four, so there is nothing for a driver to act on
 * until somebody at the desk supplies them.
 */
class TripService
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly NotificationService $notifications,
        private readonly FinanceService $finance,
        private readonly PricingService $pricing,
        private readonly BillingService $billing,
        private readonly ProofStore $proofs,
        // What decides whether a unit may leave. Injected rather than queried
        // here, so the rule about what counts as a passing check lives in one
        // place — see `mustBeCleared()`.
        private readonly InspectionService $inspections,
        // A partner's share of what the run billed. Does nothing at all for a
        // run the haulier's own crew hauled, which is most of them.
        private readonly WalletService $wallet,
        // How far the truck actually drives, which is what picks the zone.
        private readonly RoadDistance $roads,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->trips->paginate($filters, $perPage);
    }

    public function find(string $id): Trip
    {
        return $this->trips->findOrFail($id);
    }

    /**
     * The reference is the model's to assign, not the client's. This is a
     * transaction because a trip without its delivery log is a half-created
     * trip, and the Delivery Logs page would show a gap for it.
     */
    public function create(TripData $data): Trip
    {
        return DB::transaction(function () use ($data): Trip {
            $trip = $this->trips->create($data);
            $this->fillHelpers($trip, $data);
            $this->fillDistance($trip, $data);
            $this->fillPrice($trip, $data);

            DeliveryLog::create([
                'trip_id' => $trip->id,
                'status' => StatusValue::Pending->value,
            ]);

            return $trip->refresh();
        });
    }

    public function update(Trip $trip, TripData $data): Trip
    {
        return DB::transaction(function () use ($trip, $data): Trip {
            $updated = $this->trips->update($trip, $data);
            $this->fillHelpers($updated, $data);
            $this->fillDistance($updated, $data);
            $this->fillPrice($updated, $data);

            return $updated->refresh();
        });
    }

    /**
     * A customer asking for a pickup.
     *
     * Everything the office decides is deliberately absent: no driver, no
     * helper, no unit, no schedule it could not know. What the customer does
     * know is where the load is, where it is going, what it is and what it
     * weighs — and that is enough to quote them a price on the spot, which is
     * the difference between a request and a hopeful message.
     *
     * It lands as `pending`, and the desk is told, because a request nobody
     * sees is a customer waiting on silence.
     */
    public function request(TripData $data): Trip
    {
        $trip = $this->create(TripData::fromArray([
            ...$data->persistable(),
            // Not the client's to choose. A request that arrived already
            // assigned would skip the confirmation it exists to ask for.
            'status' => StatusValue::Pending->value,
        ]));

        $customer = $trip->customer?->name;

        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Dispatcher],
            icon: 'shipments',
            title: 'New delivery request',
            detail: trim("{$trip->reference} · {$trip->origin} → {$trip->destination}"
                .($customer === null ? '' : " · {$customer}")),
            tone: Tone::Warning,
        );

        return $trip;
    }

    /**
     * The desk confirming a request: pending → assigned, with the crew.
     *
     * This is the one write that turns a customer's ask into work somebody can
     * do, and it is a verb of its own rather than a status PATCH because the
     * status is the *consequence* of naming a driver, a unit and a time — not
     * a field somebody sets beside them. A form that could write `assigned`
     * without them would produce a run a driver is told to start and has no
     * vehicle for.
     *
     * Only a `pending` trip can be confirmed. Confirming one already on the
     * road, or already delivered, would move it backwards; amending those is
     * what `update` is for.
     */
    public function confirm(Trip $trip, TripData $data): Trip
    {
        abort_unless(
            $trip->status === StatusValue::Pending,
            422,
            "Only a request waiting to be confirmed can be confirmed. This run is {$trip->status->value}.",
        );

        $confirmed = DB::transaction(function () use ($trip, $data): Trip {
            $updated = $this->trips->update($trip, $data);
            $this->fillHelpers($updated, $data);
            $this->fillDistance($updated, $data);
            // The weight or the route may have been corrected on the way
            // through, so the quote is taken again here — and not once the
            // trip has been billed, which `shouldQuote` is the judge of.
            $this->fillPrice($updated, $data);

            return $updated->refresh();
        });

        // The driver is the person whose day just changed. `push` treats a
        // null user as fleet-wide, so a confirmation against a driver with no
        // login must not alert the whole roster.
        if ($confirmed->driver?->user_id !== null) {
            $this->notifications->push(
                icon: 'shipments',
                title: 'Delivery assigned to you',
                detail: "{$confirmed->reference} · {$confirmed->origin} → {$confirmed->destination}",
                tone: Tone::Info,
                userId: $confirmed->driver->user_id,
            );
        }

        return $confirmed;
    }

    /**
     * Name the run's helpers, when the caller named them.
     *
     * Only when `helper_ids` was sent: leaving it out of a PATCH leaves the
     * crew alone, and sending an empty list clears it.
     */
    private function fillHelpers(Trip $trip, TripData $data): void
    {
        if ($data->wasGiven('helper_ids')) {
            $trip->setHelpers($data->helper_ids ?? []);
        }
    }

    /**
     * Measure the run on the road, which is what picks its zone.
     *
     * Three cases, in order:
     *
     *   The desk typed a distance on this save. They know the route; it is
     *   kept exactly and marked `manual`.
     *
     *   The pins are new or moved on this save — a booking, a customer's
     *   request, a dispatcher correcting the destination. The run is measured
     *   again, even over a figure somebody typed before: that figure was for
     *   the old route. This used to fill a distance only while it was zero, so
     *   moving a pin kept the old kilometres and the old zone for good.
     *
     *   Nothing about the route changed. The distance is left alone, so a save
     *   that corrects the cargo does not spend a routing call or move a price.
     *
     * An unpinned trip is not measured: there is nothing to measure between,
     * and it is quoted in the lowest band until somebody pins it.
     */
    private function fillDistance(Trip $trip, TripData $data): void
    {
        if ($data->wasGiven('distance_total_m') && (int) $data->distance_total_m > 0) {
            $trip->forceFill(['distance_source' => 'manual'])->save();

            return;
        }

        $pinsMoved = $trip->wasRecentlyCreated
            || $trip->wasChanged(['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng']);

        if (! $trip->isMapped() || ($trip->distance_total_m > 0 && ! $pinsMoved)) {
            return;
        }

        $measured = $this->roads->between(
            (float) $trip->origin_lat,
            (float) $trip->origin_lng,
            (float) $trip->destination_lat,
            (float) $trip->destination_lng,
        );

        $trip->forceFill([
            'distance_total_m' => $measured['metres'],
            'distance_source' => $measured['source'],
        ])->save();
    }

    /**
     * Quote the haul, unless somebody priced it themselves.
     *
     * Runs after `fillDistance`, so a trip that was just pinned is charged for
     * the distance it actually covers rather than for the zero it carried a
     * moment earlier. A price sent explicitly is a negotiated rate and is left
     * exactly as it arrived — including a deliberate zero, which is how the
     * office books its own freight.
     */
    private function fillPrice(Trip $trip, TripData $data): void
    {
        if (! $this->pricing->shouldQuote($trip, $data->wasGiven('price_cents'))) {
            return;
        }

        $quote = $this->pricing->breakdown($trip);

        // The trace columns travel with the figure. Storing the price without
        // them leaves a trip nobody can explain three weeks later, once the
        // card has been edited and the pump price has moved twice.
        $trip->forceFill([
            'price_cents' => $quote->cents,
            'currency' => $trip->currency ?? $quote->currency,
            ...$quote->traceColumns(),
        ])->save();
    }

    public function delete(Trip $trip): void
    {
        $this->trips->delete($trip);
    }

    /**
     * Send a unit out. This is the one place a dispatch record is born, so the
     * time and place on it are always the real ones.
     */
    public function dispatch(Trip $trip, string $location): DispatchRecord
    {
        return DB::transaction(function () use ($trip, $location): DispatchRecord {
            $trip->update(['status' => StatusValue::InTransit->value]);

            return DispatchRecord::updateOrCreate(
                ['trip_id' => $trip->id],
                [
                    'vehicle_id' => $trip->vehicle_id,
                    'dispatched_at' => now(),
                    'location' => $location,
                    'status' => StatusValue::InTransit->value,
                ],
            );
        });
    }

    /**
     * Close a trip out.
     *
     * Delivering does five things, and that is why it cannot be a status
     * PATCH: it files the delivery log with its proof, closes the dispatch
     * record, credits the driver, puts the run's income on the Trip
     * Monitoring sheet, and raises the customer's receivable.
     *
     * The last two are the ones that used to be somebody's job to remember.
     * Income reached the ledger only if a human typed it, and an invoice only
     * if a human raised it — so the same delivered haul could be worth one
     * figure on the sheet, nothing in Billing, and something else again in
     * whatever the customer was told. All three now read the price the trip
     * was quoted at, which is the only number in the system for what the run
     * costs.
     *
     * Refuses a run that is already delivered rather than closing it twice.
     * That guard is not politeness: crediting income and raising an invoice
     * are both writes that are wrong if they happen again, and `billed_at` is
     * the belt to this braces — the office and the driver can both press
     * their button, and the money still moves once.
     */
    public function complete(Trip $trip, ProofData $proof): Trip
    {
        abort_if(
            $trip->status === StatusValue::Delivered,
            422,
            "{$trip->reference} has already been delivered.",
        );

        return DB::transaction(function () use ($trip, $proof): Trip {
            $now = now();

            $trip->update(['status' => StatusValue::Delivered->value]);

            $log = DeliveryLog::firstOrNew(['trip_id' => $trip->id]);

            $log->fill([
                'delivered_at' => $log->delivered_at ?? $now,
                'receiver_name' => $proof->receiver_name,
                'status' => StatusValue::Delivered->value,
            ]);

            // Only set when there is a new photograph. Assigning null would
            // wipe one already attached, which is the exact opposite of what
            // re-submitting proof is for.
            $path = $this->proofs->store($proof->photo);

            if ($path !== null) {
                $log->pod_image_path = $path;
            }

            // The reference is assigned by the model on save, now that
            // `delivered_at` is set. Nobody types it.
            $log->save();

            $trip->dispatchRecord?->update([
                'arrived_at' => $now,
                'status' => StatusValue::Delivered->value,
            ]);

            $trip->driver?->increment('trips_completed');

            $this->putOnTheBooks($trip->refresh(), $now);

            return $trip->refresh();
        });
    }

    /**
     * The money half of a delivery: the sheet row and the receivable.
     *
     * Guarded by `billed_at` rather than by the trip's status, because status
     * is a thing the office can move and this must happen exactly once per
     * haul whatever else is corrected afterwards.
     *
     * A trip with no unit files nothing on the ledger — there is no sheet to
     * file against, which is a real case for work booked before a vehicle is
     * picked — and a trip with no customer raises no invoice. Neither stops
     * the delivery, and neither stops `billed_at` being stamped: the billing
     * step ran, and its answer for this trip was "nothing to do".
     *
     * ## A partner's run takes a different path through all three
     *
     * **No ledger row.** The daily sheet is one company truck's costs and
     * income — diesel, the crew, the maintenance — and a partner's truck has
     * none of those in this system. `vehicle_id` is null on such a run, so the
     * existing guard already does the right thing, and that is not luck: the
     * sheet has always been per unit, and there is no unit.
     *
     * **An invoice only when the haulier is collecting.** A run the desk
     * brokered is billed exactly as it always was. A run a customer gave
     * straight to a partner is not billed at all, because the partner has
     * already billed them — raising a receivable for it would charge the
     * customer twice and put money in the aging report that nobody is owed.
     *
     * **The wallet either way.** One row, credited or charged depending on
     * which of those two it was. See `WalletService::settleTrip`.
     */
    private function putOnTheBooks(Trip $trip, CarbonInterface $at): ?Invoice
    {
        if ($trip->isBilled()) {
            return $trip->invoice;
        }

        /**
         * Who, if anyone, is owed a share of this run — and **exactly one of
         * them**.
         *
         * Two parties can be owed for a haul and they are mutually exclusive
         * by construction: either a partner carried it in their own truck, or
         * the fleet's crew carried it in a truck the fleet hired on a share.
         * A run cannot be both, because a partner's truck is a
         * `trucker_vehicle` and never sits in `vehicles`.
         *
         * It is written as an explicit either/or all the same, because "cannot
         * happen" was not true: a trip row carrying a `trucker_id` *and* a
         * revenue-share `vehicle_id` made both paths fire, the second insert
         * hit the unique index on (`trip_id`, `kind`), and the whole delivery
         * rolled back with a 500 — so the driver could not close the run at
         * all. The index was right to refuse it; the mistake was asking twice.
         *
         * The partner wins, because they are the one who demonstrably hauled
         * it. The vehicle is the fleet's record of a truck, and a run with
         * somebody else's name on it was not on that truck.
         */
        $partnerShare = $this->wallet->settleTrip($trip, $at);

        $ownerShare = $partnerShare === null
            ? $this->wallet->settleOwnerShare($trip, $at)
            : null;

        if ($trip->vehicle_id !== null) {
            $this->finance->creditTripIncome(
                vehicleId: $trip->vehicle_id,
                plate: $trip->vehicle?->plate,
                tripId: $trip->id,
                route: "{$trip->origin} → {$trip->destination}",
                date: $at,
                incomeCents: $trip->price_cents,
                customerId: $trip->customer_id,
                // Who was in the cab, so the day's driver and helper salary
                // columns have somebody to belong to. Payroll reads them for
                // anybody paid per trip — without this the sheet says what the
                // crew was paid and not who to.
                driverId: $trip->driver_id,
                helperIds: $trip->helperIds(),
                /**
                 * The owner's cut, as a cost of running that unit that day.
                 *
                 * The same figure the wallet was just credited with, not a
                 * second calculation — so Trip Monitoring, Profitability and
                 * the owner's own statement are three views of one number.
                 * Without it a rented-share truck shows its full income
                 * against almost no costs and reads as the best performer on
                 * the fleet, when the fleet in fact keeps fifteen per cent.
                 */
                ownerShareCents: $ownerShare === null ? 0 : abs($ownerShare->amount_cents),
            );
        }

        /**
         * The receivable last.
         *
         * Both shares above are computed from `price_cents` — the quote, which
         * is what every side agreed to — and raising the invoice neither
         * changes nor depends on it. Settling first means a failure to bill a
         * customer cannot leave somebody uncredited for work they have
         * demonstrably done.
         */
        $invoice = $trip->collectedByCarrier()
            ? $this->billing->raiseForTrip($trip)
            : null;

        $trip->forceFill(['billed_at' => $at])->save();

        return $invoice;
    }

    /**
     * Flip anything past its ETA to `overdue`.
     *
     * Lateness is derived from the clock, so a stored status only stays true
     * if something walks the table — this is that something, run on a schedule.
     */
    public function reconcileOverdue(): int
    {
        return Trip::query()
            ->whereNotNull('eta')
            ->where('eta', '<', now())
            ->whereIn('status', [StatusValue::InTransit->value, StatusValue::Assigned->value])
            ->update(['status' => StatusValue::Overdue->value]);
    }

    /* ------------------------------------------------------- Driver views */

    /**
     * Move scheduled work into the driver's queue once its time has come,
     * and tell them.
     *
     * `scheduled` means booked for later: it sits in Upcoming and nobody is
     * asked to act on it. When `scheduled_at` passes it becomes `assigned` —
     * work the driver can start — and that is the moment worth a
     * notification, because a booking nobody is told about is one that gets
     * missed.
     *
     * It releases to `assigned` and not to `pending`, which is where it used
     * to land. `pending` now means a request nobody has confirmed, and a
     * scheduled trip has already been confirmed — it has a driver, a unit and
     * a time. Releasing it into the unconfirmed pile would have put it back on
     * the desk's queue and taken it off the driver's.
     *
     * Idempotent by the status change itself: a released trip is no longer
     * `scheduled`, so a second run of this cannot find it and cannot alert
     * twice. That matters for something on a clock, which will be run again
     * a minute later whatever happened here.
     */
    public function releaseDueTrips(): int
    {
        $due = $this->trips->dueScheduled();

        foreach ($due as $trip) {
            DB::transaction(function () use ($trip): void {
                $trip->update(['status' => StatusValue::Assigned->value]);

                // No driver booked yet means nobody to tell. `push` treats a
                // null user as fleet-wide, and one unassigned trip must not
                // alert every driver on the roster.
                if ($trip->driver?->user_id === null) {
                    return;
                }

                $this->notifications->push(
                    icon: 'shipments',
                    title: 'Delivery ready to start',
                    detail: "{$trip->reference} · {$trip->origin} → {$trip->destination}",
                    tone: Tone::Info,
                    userId: $trip->driver->user_id,
                );
            });
        }

        return $due->count();
    }

    /**
     * The driver starting their own run: assigned → in transit.
     *
     * This is the one driver call that names a trip, because a driver with
     * several runs waiting has to say which one they are leaving on. The
     * route binding proves the trip exists; this proves it is theirs, and
     * that check is the whole reason this is not just the office's
     * `dispatch`.
     *
     * `assigned` is the only status it accepts. A `pending` run is a request
     * the desk has not confirmed — it may have no unit and no agreed time —
     * and letting a driver leave on one would mean the confirmation step
     * could be skipped by whoever pressed Start first.
     */
    public function startForDriver(string $driverId, Trip $trip, ?string $location): Trip
    {
        abort_unless($trip->driver_id === $driverId, 403, 'That run is not assigned to you.');

        abort_unless(
            $trip->status === StatusValue::Assigned,
            422,
            $trip->status === StatusValue::Pending
                ? 'That request has not been confirmed by the office yet.'
                : "Only confirmed work can be started. This run is {$trip->status->value}.",
        );

        // One run at a time. Two trips in transit for one driver would put the
        // same cab in two places, and `currentForDriver` would have to pick.
        abort_if(
            $this->currentForDriver($driverId) !== null,
            422,
            'Finish the run you are on before starting another.',
        );

        $this->mustBeCleared($trip);

        // The handset knows where it is; the booking only knows where it was
        // meant to leave from. Either is better than an empty column.
        $this->dispatch($trip, $location ?? $trip->pickup_place ?? $trip->origin);

        return $trip->refresh();
    }

    /**
     * No pre-trip check, no departure.
     *
     * The last gate before a unit rolls, and the one that is about the truck
     * rather than about the paperwork. DESIGN.md section 5.2 has the checklist
     * on the handset from the start; what it did not have was any consequence —
     * a driver could skip it and leave, which made it a form rather than a
     * check. Now the run does not start until the unit has passed one.
     *
     * Enforced here rather than in the controller so it holds for every way in:
     * the handset's Start button today, and the office starting a run on a
     * driver's behalf if that is ever added. `InspectionService` decides what
     * counts as a passing check — and it will not pass a unit on a failed brake
     * check however the form was filled in.
     *
     * The message is written to be read on a phone at a yard gate: it says what
     * is missing and where to do it, because a driver who is told "422" rings
     * the office and a driver who is told to run the checklist runs the
     * checklist.
     */
    private function mustBeCleared(Trip $trip): void
    {
        // Off for an install that records its checks somewhere else, or is
        // still rolling the handset out — see `config/cargo.php`. The check is
        // still recorded and still shown either way; this decides only whether
        // it holds the unit.
        if (! config('cargo.inspection.required_before_start', true)) {
            return;
        }

        if ($this->inspections->clearanceFor($trip) !== null) {
            return;
        }

        $attempt = $trip->latestInspection;

        abort(422, $attempt === null
            ? 'Run the pre-trip check on Inspect before you leave — the unit has not been checked for this run.'
            : sprintf(
                'This unit is held on its pre-trip check: %s. Get it seen to, then check it again.',
                implode(', ', $attempt->failures()) ?: 'the checklist was not finished',
            ));
    }

    /**
     * The driver's own hand-off: in transit → delivered, with the proof.
     *
     * Scoped to the caller rather than to a trip id, like the rest of the
     * driver endpoints — `POST trips/{trip}/complete` is the office's version
     * of this and takes an id, which on a handset would let one driver close
     * another's run by changing it.
     *
     * `currentForDriver` only ever returns an `in_transit` row, so that
     * transition is enforced by what can be found here rather than by a
     * status check: a run that was never dispatched cannot be handed over,
     * and one already delivered is no longer current, so it cannot be closed
     * and credited to the driver twice.
     */
    public function deliverForDriver(string $driverId, ProofData $proof): Trip
    {
        $trip = $this->currentForDriver($driverId);

        abort_if(
            $trip === null,
            422,
            'You have no trip in transit. A trip has to be dispatched before it can be delivered.',
        );

        return $this->complete($trip, $proof);
    }

    public function currentForDriver(string $driverId): ?Trip
    {
        return $this->trips->currentForDriver($driverId);
    }

    /**
     * Work that is confirmed and waiting to be started.
     *
     * `assigned` only. An unconfirmed request is not the driver's queue — it
     * is the desk's — and listing it here would show a driver work they
     * cannot start and were never promised.
     */
    public function pendingForDriver(string $driverId): Collection
    {
        return $this->trips->queuedForDriver($driverId, [StatusValue::Assigned->value]);
    }

    /** Work booked for a later day. */
    public function upcomingForDriver(string $driverId): Collection
    {
        return $this->trips->queuedForDriver($driverId, [StatusValue::Scheduled->value]);
    }
}
