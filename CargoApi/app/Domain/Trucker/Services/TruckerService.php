<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Enums\TruckVerification;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Repositories\TruckerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TruckerService
{
    public function __construct(
        private readonly TruckerRepository $truckers,
        private readonly NotificationService $notifications,
        private readonly TruckPhotoStore $photos,
    ) {}

    /** @param array<string, mixed> $filters */
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

    /** @return Collection<int, Trucker> */
    public function available(): Collection
    {
        return $this->truckers->takingWork();
    }

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

    public function reportPosition(Trucker $trucker, float $lat, float $lng): Trucker
    {
        $trucker->update([
            'latitude' => $lat,
            'longitude' => $lng,
            'located_at' => now(),
        ]);

        return $trucker->refresh();
    }

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

            PricingService::mustBePriced($locked);

            abort_if(
                $locked->driver_id !== null,
                422,
                'That run already has one of your own drivers on it.',
            );

            if ($vehicleId !== null) {
                $unit = $this->unitFor($trucker, $vehicleId);

                abort_unless(
                    $unit->canCarry($locked->weight_kg, $locked->truck_category_id),
                    422,
                    "{$unit->plate} cannot carry that load.",
                );
            } else {
                $trucker->loadMissing('vehicles');

                abort_if(
                    $trucker->fittingVehicle($locked->weight_kg, $locked->truck_category_id) === null,
                    422,
                    'None of that trucker\'s trucks on the road can carry this load.',
                );
            }

            $locked->update([
                'trucker_id' => $trucker->getKey(),
                'trucker_vehicle_id' => $vehicleId === null ? null : $unit->getKey(),
                'vehicle_id' => null,
                'booking_source' => BookingSource::CargoRush->value,
                'status' => StatusValue::Assigned->value,
            ]);

            $this->tell(
                $trucker,
                'A job has been assigned to you',
                "{$locked->reference} · {$locked->origin} → {$locked->destination} · {$locked->weight_kg} kg. Choose which truck takes it on My Trips.",
                Tone::Info,
            );

            return $locked->refresh();
        });
    }

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

    public function vehicles(Trucker $trucker): Collection
    {
        return $trucker->vehicles()->orderBy('plate')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, UploadedFile>  $photos
     */
    public function saveVehicle(
        Trucker $trucker,
        array $attributes,
        ?string $vehicleId = null,
        array $photos = [],
        ?User $office = null,
    ): TruckerVehicle {
        if ($vehicleId === null) {
            $paths = collect($photos)
                ->mapWithKeys(fn (UploadedFile $file, string $slot): array => [
                    TruckPhotoStore::column($slot) => $this->photos->store($file, $trucker->getKey()),
                ])
                ->all();

            return TruckerVehicle::create([
                ...$attributes,
                ...$paths,
                'trucker_id' => $trucker->getKey(),
                ...($office === null
                    ? ['verification' => TruckVerification::Pending->value]
                    : $this->verifiedBy($office)),
            ])->refresh();
        }

        $vehicle = $this->vehicleOf($trucker, $vehicleId);

        $recheck = $office === null
            && isset($attributes['plate'])
            && strcasecmp((string) $attributes['plate'], $vehicle->plate) !== 0;

        $vehicle->update([
            ...$attributes,
            ...($recheck ? $this->backForChecking() : []),
        ]);

        return $vehicle->refresh();
    }

    /** @param array<string, UploadedFile> $photos */
    public function resubmitPhotos(Trucker $trucker, string $vehicleId, array $photos): TruckerVehicle
    {
        $vehicle = $this->vehicleOf($trucker, $vehicleId);

        $paths = collect($photos)
            ->mapWithKeys(fn (UploadedFile $file, string $slot): array => [
                TruckPhotoStore::column($slot) => $this->photos->replace(
                    $vehicle->getAttribute(TruckPhotoStore::column($slot)),
                    $file,
                    $trucker->getKey(),
                ),
            ])
            ->all();

        $vehicle->update([...$paths, ...$this->backForChecking()]);

        return $vehicle->refresh();
    }

    public function verifyVehicle(Trucker $trucker, string $vehicleId, User $office): TruckerVehicle
    {
        $vehicle = $this->vehicleOf($trucker, $vehicleId);

        abort_if($vehicle->isVerified(), 422, "{$vehicle->plate} is already verified.");

        $vehicle->update($this->verifiedBy($office));

        $this->tell(
            $trucker,
            "{$vehicle->plate} is verified",
            'Cargo Rush has checked your truck. It can take jobs now.',
            Tone::Success,
        );

        return $vehicle->refresh();
    }

    public function rejectVehicle(Trucker $trucker, string $vehicleId, string $reason): TruckerVehicle
    {
        $vehicle = $this->vehicleOf($trucker, $vehicleId);

        $vehicle->update([
            ...$this->backForChecking(),
            'verification' => TruckVerification::Rejected->value,
            'rejection_reason' => $reason,
        ]);

        $this->tell(
            $trucker,
            "{$vehicle->plate} was not verified",
            "{$reason} Send new photos from My trucks.",
            Tone::Danger,
        );

        return $vehicle->refresh();
    }

    private function vehicleOf(Trucker $trucker, string $vehicleId): TruckerVehicle
    {
        $vehicle = $trucker->vehicles()->find($vehicleId);

        abort_if($vehicle === null, 404, 'That truck is not on this account.');

        return $vehicle;
    }

    /** @return array<string, mixed> */
    private function verifiedBy(User $office): array
    {
        return [
            'verification' => TruckVerification::Verified->value,
            'verified_at' => now(),
            'verified_by' => $office->getKey(),
            'rejection_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function backForChecking(): array
    {
        return [
            'verification' => TruckVerification::Pending->value,
            'verified_at' => null,
            'verified_by' => null,
            'rejection_reason' => null,
        ];
    }

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
