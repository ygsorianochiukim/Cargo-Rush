<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Illuminate\Support\Facades\DB;

class TripCostPostingService
{
    public function __construct(private readonly FinanceService $finance) {}

    public function syncTrip(Trip $trip): void
    {
        $log = DeliveryLog::query()->where('trip_id', $trip->id)->first();

        if ($log !== null) {
            $this->sync($log);
        }
    }

    public function sync(DeliveryLog $log): DeliveryLog
    {
        return DB::transaction(function () use ($log): DeliveryLog {
            $this->takeOff($log);

            $trip = Trip::withTrashed()->find($log->trip_id);

            if (! $this->postable($log, $trip)) {
                return $log;
            }

            $fuel = (int) $log->fuel_cost_cents;
            $driver = (int) $trip->driver_allowance_cents;
            $helper = (int) $trip->helper_allowance_cents;

            if ($fuel + $driver + $helper === 0) {
                return $log;
            }

            $truck = $this->finance->truckForVehicle($trip->vehicle_id, $trip->vehicle?->plate);
            $on = $log->delivered_at->copy()->startOfDay();

            $this->finance->chargeTripCosts($truck->getKey(), $on, $fuel, $driver + $helper);

            $log->forceFill([
                'posted_fuel_cents' => $fuel,
                'posted_driver_allowance_cents' => $driver,
                'posted_helper_allowance_cents' => $helper,
                'posted_truck_id' => $truck->getKey(),
                'posted_on' => $on->toDateString(),
            ])->saveQuietly();

            return $log;
        });
    }

    public function takeOff(DeliveryLog $log): void
    {
        if ($log->posted_truck_id !== null && $log->posted_on !== null) {
            $this->finance->chargeTripCosts(
                $log->posted_truck_id,
                $log->posted_on,
                -(int) $log->posted_fuel_cents,
                -((int) $log->posted_driver_allowance_cents + (int) $log->posted_helper_allowance_cents),
            );
        }

        $log->forceFill([
            'posted_fuel_cents' => 0,
            'posted_driver_allowance_cents' => 0,
            'posted_helper_allowance_cents' => 0,
            'posted_truck_id' => null,
            'posted_on' => null,
        ])->saveQuietly();
    }

    private function postable(DeliveryLog $log, ?Trip $trip): bool
    {
        return $trip !== null
            && ! $trip->trashed()
            && ! $log->trashed()
            && $log->status === StatusValue::Delivered
            && $log->delivered_at !== null
            && $trip->vehicle_id !== null
            && ! $trip->hauledByPartner();
    }
}
