<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Services;

use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\DB;

class FuelPostingService
{
    public function __construct(private readonly FinanceService $finance) {}

    public function sync(FuelRecord $fill): FuelRecord
    {
        return DB::transaction(function () use ($fill): FuelRecord {
            $this->takeOff($fill);

            $truck = $this->truckFor($fill);

            if ($truck === null) {
                return $fill;
            }

            $on = $fill->logged_at->copy()->startOfDay();
            $cents = (int) $fill->amount_cents;

            $this->finance->chargeFuel($truck->getKey(), $on, $cents);

            $fill->forceFill([
                'posted_cents' => $cents,
                'posted_truck_id' => $truck->getKey(),
                'posted_on' => $on->toDateString(),
            ])->saveQuietly();

            return $fill;
        });
    }

    public function takeOff(FuelRecord $fill): void
    {
        if ($fill->isPosted() && $fill->posted_on !== null) {
            $this->finance->chargeFuel($fill->posted_truck_id, $fill->posted_on, -(int) $fill->posted_cents);
        }

        $fill->forceFill([
            'posted_cents' => 0,
            'posted_truck_id' => null,
            'posted_on' => null,
        ])->saveQuietly();
    }

    private function truckFor(FuelRecord $fill): ?Truck
    {
        if ($fill->trashed()
            || $fill->status !== StatusValue::Active
            || $fill->vehicle_id === null
            || $fill->logged_at === null
            || (int) $fill->amount_cents <= 0) {
            return null;
        }

        return $this->finance->truckForVehicle($fill->vehicle_id, $fill->vehicle?->plate);
    }
}
