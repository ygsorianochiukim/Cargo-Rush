<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\PricingService;
use App\Domain\Billing\Services\TaxService;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Services\ProofStore;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TripService
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly NotificationService $notifications,
        private readonly FinanceService $finance,
        private readonly PricingService $pricing,
        private readonly BillingService $billing,
        private readonly ProofStore $proofs,
        private readonly InspectionService $inspections,
        private readonly WalletService $wallet,
        private readonly RoadDistance $roads,
        private readonly TaxService $tax,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->trips->paginate($filters, $perPage);
    }

    public function find(string $id): Trip
    {
        return $this->trips->findOrFail($id);
    }

    public function create(TripData $data): Trip
    {
        return DB::transaction(function () use ($data): Trip {
            $trip = $this->trips->create($data);
            $this->fillHelpers($trip, $data);
            $this->fillDistance($trip, $data);
            $this->fillTruckSize($trip, $data);
            $this->fillPrice($trip, $data);
            $this->mustBePricedToGoOut($trip);

            DeliveryLog::create([
                'trip_id' => $trip->id,
                'status' => StatusValue::Pending->value,
            ]);

            return $trip->refresh();
        });
    }

    public function update(Trip $trip, TripData $data): Trip
    {
        $this->mustLeaveTheBooksAlone($trip, $data);

        return DB::transaction(function () use ($trip, $data): Trip {
            $updated = $this->trips->update($trip, $data);
            $this->fillHelpers($updated, $data);
            $this->fillDistance($updated, $data);
            $this->fillTruckSize($updated, $data);
            $this->fillPrice($updated, $data);
            $this->mustBePricedToGoOut($updated);

            return $updated->refresh();
        });
    }

    private function mustBePricedToGoOut(Trip $trip): void
    {
        if (in_array($trip->status, [StatusValue::Pending, StatusValue::Cancelled], true)) {
            return;
        }

        PricingService::mustBePriced($trip);
    }

    public function request(TripData $data): Trip
    {
        $trip = $this->create(TripData::fromArray([
            ...$data->persistable(),
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
            $this->fillTruckSize($updated, $data);
            $this->fillPrice($updated, $data);

            PricingService::mustBePriced($updated);

            return $updated->refresh();
        });

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

    private function fillHelpers(Trip $trip, TripData $data): void
    {
        if ($data->wasGiven('helper_ids')) {
            $trip->setHelpers($data->helper_ids ?? []);
        }
    }

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

    private function fillTruckSize(Trip $trip, TripData $data): void
    {
        if ($trip->isBilled() || $data->wasGiven('truck_category_id') || ! $data->wasGiven('vehicle_id')) {
            return;
        }

        $sized = $trip->vehicle_id === null
            ? null
            : Vehicle::query()->whereKey($trip->vehicle_id)->value('truck_category_id');

        if ($sized === null && $trip->truck_category_id !== null) {
            $carried = TruckCategory::query()->whereKey($trip->truck_category_id)->value('cbm');
            $sized = $carried === null ? $trip->truck_category_id : null;
        }

        if ($sized !== $trip->truck_category_id) {
            $trip->forceFill(['truck_category_id' => $sized])->save();
        }
    }

    private function fillPrice(Trip $trip, TripData $data): void
    {
        $given = $data->wasGiven('price_cents');

        if ($given && $data->price_cents !== null && ! $trip->isBilled()) {
            $trip->forceFill([
                'pricing_source' => 'manual',
                'pricing_note' => null,
                'pricing_bracket_id' => null,
                'fuel_adjustment_bp' => 0,
                'fuel_surcharge_cents' => 0,
            ])->save();

            return;
        }

        $handedBack = $given && $data->price_cents === null;

        if ($handedBack && ! $trip->isBilled()) {
            $trip->forceFill(['pricing_source' => null])->save();
        }

        if (! $this->pricing->shouldQuote($trip, $given && ! $handedBack)) {
            return;
        }

        $quote = $this->pricing->breakdown($trip);

        $trip->forceFill([
            'price_cents' => $quote->cents,
            'currency' => $trip->currency ?? $quote->currency,
            ...$quote->traceColumns(),
        ])->save();
    }

    public function delete(Trip $trip): void
    {
        abort_if(
            $trip->isBilled(),
            422,
            "{$trip->reference} has been delivered and billed, so it cannot be deleted. "
                .'Its income, its invoice and any trucker settlement are already on the books.',
        );

        $this->trips->delete($trip);
    }

    private function mustLeaveTheBooksAlone(Trip $trip, TripData $data): void
    {
        if (! $trip->isBilled()) {
            return;
        }

        $moved = array_keys(array_filter([
            'price' => $data->wasGiven('price_cents') && (int) $data->price_cents !== (int) $trip->price_cents,
            'unit' => $data->wasGiven('vehicle_id') && $data->vehicle_id !== $trip->vehicle_id,
            'customer' => $data->wasGiven('customer_id') && $data->customer_id !== $trip->customer_id,
            'status' => $data->wasGiven('status') && $data->status !== $trip->status,
        ]));

        abort_if(
            $moved !== [],
            422,
            "{$trip->reference} has been delivered and billed, so its ".implode(', ', $moved)
                .' can no longer be changed: its income, its invoice and any trucker settlement are already on the books. '
                .'Notes and other details can still be edited.',
        );
    }

    public function dispatch(Trip $trip, string $location): DispatchRecord
    {
        PricingService::mustBePriced($trip);

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

    public function complete(Trip $trip, ProofData $proof, ?CarbonInterface $at = null): Trip
    {
        abort_if(
            $trip->status === StatusValue::Delivered,
            422,
            "{$trip->reference} has already been delivered.",
        );

        PricingService::mustBePriced($trip);

        return DB::transaction(function () use ($trip, $proof, $at): Trip {
            $now = $at ?? now();

            $trip->update(['status' => StatusValue::Delivered->value]);

            $log = DeliveryLog::firstOrNew(['trip_id' => $trip->id]);

            $log->fill([
                'delivered_at' => $log->delivered_at ?? $now,
                'receiver_name' => $proof->receiver_name,
                'status' => StatusValue::Delivered->value,
            ]);

            $path = $this->proofs->store($proof->photo);

            if ($path !== null) {
                $log->pod_image_path = $path;
            }

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

    private function putOnTheBooks(Trip $trip, CarbonInterface $at): ?Invoice
    {
        if ($trip->isBilled()) {
            return $trip->invoice;
        }

        $partnerShare = $this->wallet->settleTrip($trip, $at);

        $ownerShare = $trip->hauledByPartner()
            ? null
            : $this->wallet->settleOwnerShare($trip, $at);

        if ($trip->vehicle_id !== null && ! $trip->hauledByPartner()) {
            $this->finance->creditTripIncome(
                vehicleId: $trip->vehicle_id,
                plate: $trip->vehicle?->plate,
                tripId: $trip->id,
                route: "{$trip->origin} → {$trip->destination}",
                date: $at,
                incomeCents: $this->tax->on((int) $trip->price_cents, $trip->customer)->net_cents,
                customerId: $trip->customer_id,
                driverId: $trip->driver_id,
                helperIds: $trip->helperIds(),
                ownerShareCents: $ownerShare === null ? 0 : abs($ownerShare->amount_cents),
            );
        }

        $invoice = $trip->collectedByCarrier()
            ? $this->billing->raiseForTrip($trip, $at)
            : null;

        $trip->forceFill(['billed_at' => $at])->save();

        return $invoice;
    }

    public function reconcileOverdue(): int
    {
        return Trip::query()
            ->whereNotNull('eta')
            ->where('eta', '<', now())
            ->whereIn('status', [StatusValue::InTransit->value, StatusValue::Assigned->value])
            ->update(['status' => StatusValue::Overdue->value]);
    }

    public function releaseDueTrips(): int
    {
        $due = $this->trips->dueScheduled();

        foreach ($due as $trip) {
            DB::transaction(function () use ($trip): void {
                $trip->update(['status' => StatusValue::Assigned->value]);

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

        abort_if(
            $this->currentForDriver($driverId) !== null,
            422,
            'Finish the run you are on before starting another.',
        );

        $this->mustBeCleared($trip);

        $this->dispatch($trip, $location ?? $trip->pickup_place ?? $trip->origin);

        return $trip->refresh();
    }

    private function mustBeCleared(Trip $trip): void
    {
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

    public function pendingForDriver(string $driverId): Collection
    {
        return $this->trips->queuedForDriver($driverId, [StatusValue::Assigned->value]);
    }

    public function upcomingForDriver(string $driverId): Collection
    {
        return $this->trips->queuedForDriver($driverId, [StatusValue::Scheduled->value]);
    }
}
