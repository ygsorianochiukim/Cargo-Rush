<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Carbon\CarbonInterface;
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
            $toll = (int) $trip->toll_cents;

            if ($fuel + $driver + $helper + $toll === 0) {
                return $log;
            }

            $truck = $this->finance->truckForVehicle($trip->vehicle_id, $trip->vehicle?->plate);
            $on = $log->delivered_at->copy()->startOfDay();

            $this->finance->chargeTripCosts($truck->getKey(), $on, $fuel, $driver + $helper);

            $log->forceFill([
                'posted_fuel_cents' => $fuel,
                'posted_driver_allowance_cents' => $driver,
                'posted_helper_allowance_cents' => $helper,
                'posted_toll_expense_id' => $toll > 0 ? $this->chargeToll($trip, $truck->getKey(), $on, $toll)->getKey() : null,
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

        if ($log->posted_toll_expense_id !== null) {
            Expense::query()->find($log->posted_toll_expense_id)?->delete();
        }

        $log->forceFill([
            'posted_fuel_cents' => 0,
            'posted_driver_allowance_cents' => 0,
            'posted_helper_allowance_cents' => 0,
            'posted_toll_expense_id' => null,
            'posted_truck_id' => null,
            'posted_on' => null,
        ])->saveQuietly();
    }

    private function chargeToll(Trip $trip, string $truckId, CarbonInterface $on, int $toll): Expense
    {
        $category = ExpenseCategory::firstOrCreate(['key' => 'toll-parking'], [
            'name' => 'Toll & Parking',
            'description' => 'Toll gates, terminal fees and parking.',
            'icon' => 'route',
            'position' => 30,
        ]);

        return Expense::create([
            'category_id' => $category->getKey(),
            'truck_id' => $truckId,
            'trip_id' => $trip->getKey(),
            'vehicle_id' => $trip->vehicle_id,
            'driver_id' => $trip->driver_id,
            'ledger_entry_id' => $this->finance->openDailyRowForTruck($truckId, $on)->getKey(),
            'date' => $on->toDateString(),
            'amount_cents' => $toll,
            'currency' => 'PHP',
            'reference' => $trip->reference,
            'note' => sprintf('Toll for trip %s', $trip->reference),
            'status' => StatusValue::Active->value,
        ]);
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
