<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Billing\DTO\InvoiceData;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Billing\Services\PricingService;
use App\Domain\Billing\Services\TaxService;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Services\ProofStore;
use App\Domain\Delivery\Services\TripCostPostingService;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\InvoiceDirection;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly PaymentService $payments,
        private readonly TripCostPostingService $costs,
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
            $this->fillZonePin($trip, $data);
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
        return DB::transaction(function () use ($trip, $data): Trip {
            if ($trip->isBilled() && $data->wasGiven('status') && $data->status !== $trip->status) {
                $this->unbill($trip);
            }

            $before = clone $trip;
            $this->repricing = $trip->isBilled() && $this->pricingMoves($trip, $data);

            try {
                $updated = $this->trips->update($trip, $data);
                $this->fillHelpers($updated, $data);
                $this->fillDistance($updated, $data);
                $this->fillTruckSize($updated, $data);
                $this->fillZonePin($updated, $data);
                $this->fillPrice($updated, $data);
                $this->mustBePricedToGoOut($updated);
            } finally {
                $this->repricing = false;
            }

            $updated->refresh();

            if ($before->isBilled() && $this->booksMoved($before, $updated)) {
                $this->takeOffTheBooks($before);
                $this->rebook($updated->refresh());
            }

            $this->costs->syncTrip($updated->refresh());

            return $updated->refresh();
        });
    }

    private bool $repricing = false;

    private function frozen(Trip $trip): bool
    {
        return $trip->isBilled() && ! $this->repricing;
    }

    private function pricingMoves(Trip $trip, TripData $data): bool
    {
        $differs = static fn (string $key, mixed $now): bool => $data->wasGiven($key) && $data->{$key} != $now;

        $pinned = $trip->zone_pinned ? $trip->pricing_zone_id : null;

        return ($data->wasGiven('price_cents') && $data->price_cents !== $trip->price_cents)
            || $differs('weight_kg', $trip->weight_kg)
            || $differs('distance_total_m', $trip->distance_total_m)
            || $differs('vehicle_id', $trip->vehicle_id)
            || $differs('truck_category_id', $trip->truck_category_id)
            || $differs('origin_lat', $trip->origin_lat)
            || $differs('origin_lng', $trip->origin_lng)
            || $differs('destination_lat', $trip->destination_lat)
            || $differs('destination_lng', $trip->destination_lng)
            || ($data->wasGiven('pricing_zone_id') && ($data->pricing_zone_id ?: null) !== $pinned);
    }

    private function booksMoved(Trip $before, Trip $after): bool
    {
        return (int) $before->price_cents !== (int) $after->price_cents
            || $before->vehicle_id !== $after->vehicle_id
            || $before->customer_id !== $after->customer_id;
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
            $this->fillZonePin($updated, $data);
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

    private function fillZonePin(Trip $trip, TripData $data): void
    {
        if (! $data->wasGiven('pricing_zone_id') || $this->frozen($trip)) {
            return;
        }

        $pinned = $data->pricing_zone_id !== null && $data->pricing_zone_id !== '';

        if ((bool) $trip->zone_pinned !== $pinned) {
            $trip->forceFill(['zone_pinned' => $pinned])->save();
        }
    }

    private function fillTruckSize(Trip $trip, TripData $data): void
    {
        if ($this->frozen($trip) || $data->wasGiven('truck_category_id') || ! $data->wasGiven('vehicle_id')) {
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

        if ($given && $data->price_cents !== null && ! $this->frozen($trip)) {
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

        if ($handedBack && ! $this->frozen($trip)) {
            $trip->forceFill(['pricing_source' => null])->save();
        }

        if ($this->frozen($trip) || ($given && ! $handedBack) || $trip->isManuallyPriced()) {
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
        DB::transaction(function () use ($trip): void {
            if ($trip->isBilled()) {
                $this->unbill($trip, 'it cannot be deleted. Remove the payment first to delete it.');
            }

            $this->trips->delete($trip);
            $this->costs->syncTrip($trip);
        });
    }

    private function unbill(Trip $trip, string $refusal = 'it must stay delivered. Remove the payment first to change its status.'): void
    {
        $invoice = Invoice::query()
            ->where('trip_id', $trip->id)
            ->where('direction', InvoiceDirection::Receivable->value)
            ->first();

        $paid = $invoice === null ? 0 : (int) $invoice->allocations()->sum('amount_cents');

        abort_if(
            $paid > 0,
            422,
            "{$trip->reference} already has payments on {$invoice?->number}, so {$refusal}",
        );

        $this->takeOffTheBooks($trip);

        if ($invoice !== null && $invoice->status !== StatusValue::Cancelled) {
            $this->billing->update($invoice, InvoiceData::fromArray(['status' => StatusValue::Cancelled->value]));
        }

        DeliveryLog::query()->where('trip_id', $trip->id)->update([
            'delivered_at' => null,
            'receiver_name' => null,
            'status' => StatusValue::Pending->value,
        ]);

        $this->costs->syncTrip($trip);

        $trip->dispatchRecord?->update(['arrived_at' => null, 'status' => StatusValue::InTransit->value]);

        if ((int) $trip->driver?->trips_completed > 0) {
            $trip->driver->decrement('trips_completed');
        }

        $trip->forceFill(['billed_at' => null])->save();
    }

    private function takeOffTheBooks(Trip $trip): void
    {
        $ownerShare = $this->wallet->unsettleTrip($trip);

        if ($trip->vehicle_id !== null && ! $trip->hauledByPartner()) {
            $this->finance->takeBackTripIncome(
                vehicleId: $trip->vehicle_id,
                date: $trip->billed_at,
                incomeCents: $this->tax->on((int) $trip->price_cents, $trip->customer)->net_cents,
                ownerShareCents: $ownerShare,
            );
        }
    }

    private function rebook(Trip $trip): void
    {
        $at = $trip->billed_at;

        $this->postToTheBooks($trip, $at);

        $invoice = Invoice::withTrashed()
            ->where('trip_id', $trip->id)
            ->where('direction', InvoiceDirection::Receivable->value)
            ->first();

        $collectible = $trip->collectedByCarrier() && $trip->customer_id !== null && (int) $trip->price_cents > 0;

        if ($invoice === null) {
            if ($collectible) {
                $this->billing->raiseForTrip($trip, $at);
            }

            return;
        }

        if ($invoice->trashed()) {
            return;
        }

        $paid = (int) $invoice->allocations()->sum('amount_cents');

        if (! $collectible) {
            abort_if($paid > 0, 422, "{$invoice->number} already has payments against it, so this run must keep a customer and a price. Remove the payment first.");

            $this->billing->delete($invoice);

            return;
        }

        abort_if(
            $paid > 0 && $invoice->customer_id !== $trip->customer_id,
            422,
            "{$invoice->number} already has payments from its customer, so the run cannot move to another customer. Remove the payment first.",
        );

        $this->billing->update($invoice, InvoiceData::fromArray([
            'customer_id' => $trip->customer_id,
            'amount_cents' => (int) $trip->price_cents,
            'currency' => $trip->currency ?? $invoice->currency,
        ]));
    }

    /**
     * @param  array{amount_cents?: int|null, paid_on?: string|null, method?: string|null, reference?: string|null}  $payment
     *
     * @throws ValidationException
     */
    public function recordPayment(Trip $trip, array $payment, ?int $userId = null): ?Payment
    {
        $invoice = Invoice::query()
            ->where('trip_id', $trip->id)
            ->where('direction', InvoiceDirection::Receivable->value)
            ->first();

        if ($invoice === null) {
            throw ValidationException::withMessages([
                'payment_status' => ["{$trip->reference} has no invoice to pay. It needs a customer and a price, and must be booked through Cargo Rush."],
            ]);
        }

        $balance = $invoice->balanceCents();

        if ($balance <= 0 && ! isset($payment['amount_cents'])) {
            return null;
        }

        $amount = (int) ($payment['amount_cents'] ?? $balance);
        $delivered = DeliveryLog::query()->where('trip_id', $trip->id)->value('delivered_at');

        return $this->payments->record(
            [
                'customer_id' => $invoice->customer_id,
                'direction' => $invoice->direction->value,
                'amount_cents' => $amount,
                'currency' => $invoice->currency,
                'paid_on' => $payment['paid_on'] ?? ($delivered === null ? now() : Carbon::parse($delivered))->toDateString(),
                'method' => $payment['method'] ?? 'bank_transfer',
                'reference' => $payment['reference'] ?? null,
            ],
            [['invoice_id' => $invoice->id, 'amount_cents' => $amount]],
            $userId,
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

            $this->costs->sync($log);

            return $trip->refresh();
        });
    }

    private function putOnTheBooks(Trip $trip, CarbonInterface $at): ?Invoice
    {
        if ($trip->isBilled()) {
            return $trip->invoice;
        }

        $this->postToTheBooks($trip, $at);

        $invoice = $trip->collectedByCarrier()
            ? $this->billing->reopenForTrip($trip, $at) ?? $this->billing->raiseForTrip($trip, $at)
            : null;

        $trip->forceFill(['billed_at' => $at])->save();

        return $invoice;
    }

    private function postToTheBooks(Trip $trip, CarbonInterface $at): void
    {
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
