<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Repositories\TruckerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The desk's side of the partner roster: vetting, assigning and standing down.
 *
 * Everything here is a decision a human at the haulier makes about somebody
 * outside it, which is why none of it is reachable from the handset. The
 * partner's own service is `JobBoardService`; the line between the two is the
 * same line the driver module draws between `DriverController` and
 * `DriverTripController`.
 */
class TruckerService
{
    public function __construct(
        private readonly TruckerRepository $truckers,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->truckers->paginate($filters, $perPage);
    }

    public function find(string $id): Trucker
    {
        return $this->truckers->findOrFail($id);
    }

    public function forUser(int $userId): ?Trucker
    {
        return $this->truckers->findByUser($userId);
    }

    /**
     * Who the desk could hand a load to right now.
     *
     * Vetted, switched on, and holding a truck that is not in the shop. Not the
     * same as the roster filtered by status, which is why it is its own call:
     * the last clause is a question about the rows underneath rather than about
     * a column on this one.
     *
     * @return Collection<int, Trucker>
     */
    public function available(): Collection
    {
        return $this->truckers->takingWork();
    }

    /**
     * Approve a registration.
     *
     * The moment somebody who signed themselves up on a phone becomes somebody
     * the haulier will hand a customer's pallet to. A deliberate act by a named
     * person, and the notification back to the partner is not a courtesy —
     * until it arrives their job board is empty and indistinguishable from a
     * broken one.
     */
    public function approve(Trucker $trucker): Trucker
    {
        abort_if(
            $trucker->status === StatusValue::Active,
            422,
            'That trucker is already approved.',
        );

        $trucker->update(['status' => StatusValue::Active->value]);

        $this->tell(
            $trucker,
            'You are approved',
            'You can go online and start taking jobs.',
            Tone::Success,
        );

        return $trucker->refresh();
    }

    /**
     * Stand a partner down.
     *
     * Their standing, not their switch: `is_online` is the partner's own and
     * flipping it here would be undone the next time they opened the app.
     * Setting `status` is what actually stops them, because the job board and
     * the accept both ask `canTakeWork()`, which requires both.
     *
     * Work already on them is deliberately left alone. A run in transit has a
     * customer waiting at the far end, and cancelling it because a
     * disagreement was recorded in the office would strand a pallet — the desk
     * reassigns what it wants reassigned, on the trip board, with its eyes
     * open.
     */
    public function suspend(Trucker $trucker, ?string $reason = null): Trucker
    {
        $trucker->update(['status' => StatusValue::Inactive->value]);

        $this->tell(
            $trucker,
            'Your account has been put on hold',
            $reason ?? 'Contact the office for details.',
            Tone::Danger,
        );

        return $trucker->refresh();
    }

    /**
     * The partner's own switch: are they taking work right now?
     *
     * Refused outright for anybody not yet vetted, rather than quietly stored.
     * A `pending` partner flipping themselves online and then seeing an empty
     * board would conclude the app is broken; being told why is the whole
     * difference.
     *
     * The position rides along with it, because the two are the same act: going
     * online is saying "I am here, and available", and a partner who went
     * online yesterday two hundred kilometres away should not be top of
     * today's board.
     */
    public function setOnline(Trucker $trucker, bool $online, ?float $lat = null, ?float $lng = null): Trucker
    {
        abort_unless(
            $trucker->isVetted(),
            403,
            'Your registration is still being reviewed. You will be able to go online once it is approved.',
        );

        $trucker->update([
            'is_online' => $online,
            ...($lat === null || $lng === null ? [] : [
                'latitude' => $lat,
                'longitude' => $lng,
                'located_at' => now(),
            ]),
        ]);

        return $trucker->refresh();
    }

    /**
     * Where they are, reported from the handset.
     *
     * Its own call rather than a side effect of the switch, because it happens
     * at a different rhythm — once when they go online, and then whenever the
     * app has a fix worth sending. Kept off `gps_pings`, which hang off a run:
     * the question the board asks is who is near a load that has no run yet.
     */
    public function reportPosition(Trucker $trucker, float $lat, float $lng): Trucker
    {
        $trucker->update([
            'latitude' => $lat,
            'longitude' => $lng,
            'located_at' => now(),
        ]);

        return $trucker->refresh();
    }

    /**
     * Give a partner a run, from the desk.
     *
     * The other half of `JobBoardService::accept`, and the difference between
     * them is the money. This is the haulier brokering work: it quoted the
     * customer, it will invoice and collect, and the partner is paid their
     * share out of it — so the run stays `cargo_rush` and the wallet is
     * credited at delivery rather than charged.
     *
     * What it is *for* is the case the board cannot serve: a load nobody nearby
     * picked up, or one too far out for the haulier's own units to be worth
     * sending. The desk picks somebody and hands it over.
     *
     * Locked like the accept is, and for the same reason — the desk and a
     * partner can reach for the same `pending` run in the same second, and
     * whichever lands second must be told cleanly.
     */
    public function assign(Trip $trip, Trucker $trucker, ?string $vehicleId = null): Trip
    {
        abort_unless(
            $trucker->isVetted(),
            422,
            'That trucker has not been approved yet.',
        );

        return DB::transaction(function () use ($trip, $trucker, $vehicleId): Trip {
            $locked = Trip::query()->lockForUpdate()->findOrFail($trip->getKey());

            abort_unless(
                in_array($locked->status, [StatusValue::Pending, StatusValue::Scheduled], true),
                422,
                "Only work still waiting to go out can be handed to a trucker. This run is {$locked->status->value}.",
            );

            abort_if(
                $locked->driver_id !== null,
                422,
                'That run already has one of your own drivers on it.',
            );

            $unit = $this->unitFor($trucker, $vehicleId);

            abort_unless(
                $unit->canCarry($locked->weight_kg, $locked->truck_category_id),
                422,
                "{$unit->plate} cannot carry that load.",
            );

            $locked->update([
                'trucker_id' => $trucker->getKey(),
                'trucker_vehicle_id' => $unit->getKey(),
                // The haulier brokered it, so the haulier bills it. Stated
                // rather than left to the column default, because this is the
                // line that decides which way the wallet moves and it should
                // be readable at the place the decision is made.
                'booking_source' => BookingSource::CargoRush->value,
                'status' => StatusValue::Assigned->value,
            ]);

            $this->tell(
                $trucker,
                'A job has been assigned to you',
                "{$locked->reference} · {$locked->origin} → {$locked->destination}",
                Tone::Info,
            );

            return $locked->refresh();
        });
    }

    /**
     * Take a partner back off a run.
     *
     * Returns it to the **office's** queue as unclaimed `pending` work, which
     * is what it was before somebody was put on it — including resetting the
     * source, since the run is once again one nobody has brokered. It does not
     * go back onto any partner's screen: there is no open board, so a request
     * with no name on it is the desk's to place again. Refused once the money
     * has moved:
     * a delivered run has a wallet entry and possibly an invoice against it,
     * and unpicking those is a correction the office makes deliberately rather
     * than a side effect of clearing a name off a row.
     */
    public function release(Trip $trip): Trip
    {
        abort_if($trip->trucker_id === null, 422, 'No trucker is on that run.');
        abort_if(
            $trip->isBilled(),
            422,
            'That run has already been closed out and paid on. Correct it in the wallet instead.',
        );

        $trip->update([
            'trucker_id' => null,
            'trucker_vehicle_id' => null,
            'booking_source' => BookingSource::CargoRush->value,
            'status' => StatusValue::Pending->value,
        ]);

        return $trip->refresh();
    }

    /** Every unit a partner has, for the office's detail screen. */
    public function vehicles(Trucker $trucker): Collection
    {
        return $trucker->vehicles()->orderBy('plate')->get();
    }

    /**
     * Add a truck, or correct one.
     *
     * Reachable by the partner for their own record and by the desk for
     * anybody's — the same write either way, because a plate is a plate. Who is
     * allowed to call it is the route's business, not this method's.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveVehicle(Trucker $trucker, array $attributes, ?string $vehicleId = null): TruckerVehicle
    {
        if ($vehicleId === null) {
            return TruckerVehicle::create([...$attributes, 'trucker_id' => $trucker->getKey()]);
        }

        $vehicle = $trucker->vehicles()->find($vehicleId);

        abort_if($vehicle === null, 404, 'That truck is not on this account.');

        $vehicle->update($attributes);

        return $vehicle->refresh();
    }

    /**
     * Which of a partner's trucks a run goes under.
     *
     * Named by the desk when it cares, and the first available one otherwise —
     * which is the whole of the choice for the many partners who own exactly
     * one truck.
     */
    private function unitFor(Trucker $trucker, ?string $vehicleId): TruckerVehicle
    {
        if ($vehicleId !== null) {
            $named = $trucker->vehicles()->find($vehicleId);

            abort_if($named === null, 404, 'That truck is not on this trucker.');

            return $named;
        }

        $unit = $trucker->activeVehicle();

        abort_if($unit === null, 422, 'That trucker has no truck available.');

        return $unit;
    }

    /** A line to the partner's handset, when they have one. */
    private function tell(Trucker $trucker, string $title, string $detail, Tone $tone): void
    {
        if ($trucker->user_id === null) {
            return;
        }

        $this->notifications->push(
            icon: 'fleet',
            title: $title,
            detail: $detail,
            tone: $tone,
            userId: $trucker->user_id,
        );
    }
}
