<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerDriver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CrewService
{
    public function __construct(
        private readonly JobBoardService $board,
        private readonly NotificationService $notifications,
    ) {}

    /** @return Collection<int, TruckerDriver> */
    public function drivers(Trucker $trucker): Collection
    {
        return $trucker->drivers()->with('user:id,email')->orderBy('name')->get();
    }

    /** @param array{name: string, phone?: ?string, licence_no: string, licence_expiry?: ?string, email: string, password: string} $attributes */
    public function add(Trucker $trucker, array $attributes): TruckerDriver
    {
        $this->mustBeVetted($trucker);

        abort_if(
            $trucker->drivers()->withTrashed()->where('licence_no', $attributes['licence_no'])->exists(),
            422,
            'You already have a driver with that licence number.',
        );

        return DB::transaction(function () use ($trucker, $attributes): TruckerDriver {
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                'password' => $attributes['password'],
                'role' => Role::TruckerDriver->value,
                'company_id' => $trucker->company_id,
            ]);

            return TruckerDriver::create([
                'trucker_id' => $trucker->getKey(),
                'user_id' => $user->getKey(),
                'name' => $attributes['name'],
                'phone' => $attributes['phone'] ?? null,
                'licence_no' => $attributes['licence_no'],
                'licence_expiry' => $attributes['licence_expiry'] ?? null,
            ])->refresh()->load('user:id,email');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Trucker $trucker, string $driverId, array $attributes): TruckerDriver
    {
        $this->mustBeVetted($trucker);

        $driver = $this->theirs($trucker, $driverId);

        DB::transaction(function () use ($driver, $attributes): void {
            $driver->update($attributes);

            if ($driver->status === StatusValue::Inactive) {
                $this->releaseUnstarted($driver);
            }
        });

        return $driver->refresh()->load('user:id,email');
    }

    public function remove(Trucker $trucker, string $driverId): void
    {
        $this->mustBeVetted($trucker);

        $driver = $this->theirs($trucker, $driverId);

        DB::transaction(function () use ($driver): void {
            $this->releaseUnstarted($driver);
            $driver->user?->tokens()->delete();
            $driver->delete();
        });
    }

    public function assign(Trucker $trucker, string $tripId, ?string $driverId, ?string $vehicleId = null): Trip
    {
        $this->mustBeVetted($trucker);

        $trip = $this->board->ownedBy($trucker, $tripId);

        abort_unless(
            in_array($trip->status, [StatusValue::Assigned, StatusValue::Scheduled, StatusValue::Overdue], true),
            422,
            "That run cannot be handed over — it is {$trip->status->value}.",
        );

        $driver = null;

        if ($driverId !== null) {
            $driver = $this->theirs($trucker, $driverId);

            abort_unless($driver->status === StatusValue::Active, 422, "{$driver->name} is stood down.");
        }

        $changes = ['trucker_driver_id' => $driverId];

        if ($vehicleId !== null) {
            $truck = $trucker->vehicles()->find($vehicleId);

            abort_if($truck === null, 404, 'That truck is not on this account.');
            abort_unless(
                $truck->isVerified(),
                422,
                "{$truck->plate} has not been verified by Cargo Rush yet.",
            );
            abort_unless(
                $truck->status === StatusValue::Available,
                422,
                "{$truck->plate} is marked as in the shop. Put it back on the road first.",
            );
            abort_unless(
                $truck->canCarry($trip->weight_kg, $trip->truck_category_id),
                422,
                "{$truck->plate} cannot carry this load ({$trip->weight_kg} kg).",
            );

            $changes['trucker_vehicle_id'] = $truck->getKey();
        }

        if ($driverId !== null && ($changes['trucker_vehicle_id'] ?? $trip->trucker_vehicle_id) === null) {
            $only = $this->board->onlyFittingTruck($trip);

            abort_if($only === null, 422, 'Choose which truck takes this run as well.');

            $changes['trucker_vehicle_id'] = $only->getKey();
        }

        $previous = $trip->trucker_driver_id;

        $trip->update($changes);

        $this->tellTheDrivers($trucker, $trip, $previous, $driver);

        return $trip->refresh()->load(['customer:id,name', 'truckerVehicle:id,plate', 'truckerDriver:id,name']);
    }

    private function tellTheDrivers(Trucker $trucker, Trip $trip, ?string $previousId, ?TruckerDriver $next): void
    {
        $from = $trucker->business_name ?: $trucker->name;

        if ($previousId !== null && $previousId !== $next?->getKey()) {
            $old = $trucker->drivers()->find($previousId);

            if ($old?->user_id !== null) {
                $this->notifications->push(
                    icon: 'route',
                    title: 'A run was taken off your list',
                    detail: "{$trip->reference} is no longer yours — {$from} moved it.",
                    tone: Tone::Info,
                    userId: $old->user_id,
                );
            }
        }

        if ($next !== null && $next->user_id !== null && $next->getKey() !== $previousId) {
            $this->notifications->push(
                icon: 'route',
                title: 'New run for you',
                detail: "{$from} handed you {$trip->reference}: {$trip->origin} to {$trip->destination}.",
                tone: Tone::Info,
                userId: $next->user_id,
            );
        }
    }

    private function releaseUnstarted(TruckerDriver $driver): void
    {
        $driver->trips()
            ->whereIn('status', [StatusValue::Assigned->value, StatusValue::Scheduled->value, StatusValue::Overdue->value])
            ->update(['trucker_driver_id' => null]);
    }

    private function theirs(Trucker $trucker, string $driverId): TruckerDriver
    {
        $driver = $trucker->drivers()->find($driverId);

        abort_if($driver === null, 404, 'That driver is not on this account.');

        return $driver;
    }

    private function mustBeVetted(Trucker $trucker): void
    {
        abort_unless(
            $trucker->isVetted(),
            403,
            'Your account is waiting for approval. You can add drivers once the office approves you.',
        );
    }
}
