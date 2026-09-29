<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Services;

use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\DB;

/**
 * `/fuel` is the one place fuel is recorded, and this puts it on the sheet.
 *
 * A fill used to be counted twice over: once as a fill logged at `/fuel`, and
 * again as whatever somebody typed into the sheet's `fuel_cents` for the same
 * receipt. Nothing could tell the two apart, so the roll-ups added them. Now an
 * **active** fill posts itself into its truck's row for the day, and the sheet's
 * Fuel column is no longer typed by hand where fills have posted — so each
 * receipt is in exactly one place, and the roll-ups stop adding fills on top.
 *
 * ## Exactly once, however many times it is saved
 *
 * The same guard as a maintenance job's `posted_cents`, one step stricter:
 * a fill records **what** it posted, **onto which sheet** and **on which day**,
 * and every save takes that exact figure off that exact row before posting
 * the fill as it now stands. So
 *
 *     logged active ₱3,000       → Truck 1, 14 Jul  +3,000
 *     corrected to ₱3,200        → Truck 1, 14 Jul  −3,000 +3,200
 *     moved to 15 Jul            → 14 Jul −3,200,  15 Jul +3,200
 *     cancelled / deleted        → 15 Jul −3,200,  posted 0
 *
 * and a vehicle change or a truck re-pointed at another vehicle never leaves
 * money on a row the fill no longer claims.
 *
 * A **pending** fill posts nothing — a request is not spend — and a fill for a
 * vehicle no truck points at posts nothing either and stays fleet overhead:
 * opening a sheet for it would invent a unit the fleet does not run.
 */
class FuelPostingService
{
    public function __construct(private readonly FinanceService $finance) {}

    /** Bring the sheet in step with the fill as it now stands. */
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

    /** Take whatever this fill put on a sheet back off it. */
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

    /** The sheet this fill belongs on, or null when it belongs on none. */
    private function truckFor(FuelRecord $fill): ?Truck
    {
        if ($fill->trashed()
            || $fill->status !== StatusValue::Active
            || $fill->vehicle_id === null
            || $fill->logged_at === null
            || (int) $fill->amount_cents <= 0) {
            return null;
        }

        return Truck::query()->where('vehicle_id', $fill->vehicle_id)->first();
    }
}
