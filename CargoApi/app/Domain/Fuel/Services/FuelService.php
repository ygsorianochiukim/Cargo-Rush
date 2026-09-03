<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Services;

use App\Domain\Fuel\DTO\FuelRecordData;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Repositories\FuelRepository;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FuelService
{
    public function __construct(private readonly FuelRepository $fuel) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->fuel->paginate($filters, $perPage);
    }

    /**
     * Logging a fill also moves the vehicle's odometer forward — the receipt
     * carries the reading, and the Fuel module is where it actually gets seen.
     * Only forward: a mis-keyed low reading must not rewind the unit.
     */
    public function log(FuelRecordData $data): FuelRecord
    {
        return DB::transaction(function () use ($data): FuelRecord {
            $record = $this->fuel->create($data);

            if ($data->vehicle_id !== null && $data->odometer_km !== null) {
                Vehicle::query()
                    ->whereKey($data->vehicle_id)
                    ->where('odometer_km', '<', $data->odometer_km)
                    ->update(['odometer_km' => $data->odometer_km]);
            }

            return $record;
        });
    }

    public function update(FuelRecord $record, FuelRecordData $data): FuelRecord
    {
        return $this->fuel->update($record, $data);
    }

    public function delete(FuelRecord $record): void
    {
        $this->fuel->delete($record);
    }

    /**
     * The budget tile.
     *
     * Only `daily_budget_cents` and `open_requests` are stored. Spend and the
     * month projection are summed from the records themselves, so the tile
     * cannot disagree with the table printed underneath it.
     *
     * @return array<string, mixed>
     */
    public function budget(?Carbon $on = null): array
    {
        $day = $on ?? now();
        $budget = $this->fuel->budgetFor($day);

        $spentToday = $this->fuel->spentBetween($day->copy()->startOfDay(), $day->copy()->endOfDay());

        return [
            'date' => $day->toDateString(),
            'daily_budget_cents' => $budget?->daily_budget_cents ?? 0,
            'spent_today_cents' => $spentToday,
            'currency' => $budget?->currency ?? 'PHP',
            'projection_cents' => $this->monthProjection($day),
            'open_requests' => $this->fuel->openRequests(),
        ];
    }

    /**
     * Month-end spend, projected from the daily rate so far.
     *
     * Straight-line on purpose: the run rate is the number a controller can
     * actually argue with, and anything cleverer would need a forecast model
     * this business does not have.
     */
    private function monthProjection(Carbon $day): int
    {
        $start = $day->copy()->startOfMonth();
        $spent = $this->fuel->spentBetween($start, $day->copy()->endOfDay());

        $elapsed = max(1, $start->diffInDays($day) + 1);

        return (int) round($spent / $elapsed * $day->daysInMonth);
    }
}
