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
    public function __construct(
        private readonly FuelRepository $fuel,
        private readonly FuelPostingService $posting,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->fuel->paginate($filters, $perPage);
    }

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

            return $this->posting->sync($record)->refresh();
        });
    }

    public function update(FuelRecord $record, FuelRecordData $data): FuelRecord
    {
        return DB::transaction(fn (): FuelRecord => $this->posting
            ->sync($this->fuel->update($record, $data))
            ->refresh());
    }

    public function delete(FuelRecord $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->posting->takeOff($record);
            $this->fuel->delete($record);
        });
    }

    /** @return array<string, mixed> */
    public function budget(?Carbon $on = null): array
    {
        $day = $on ?? now();
        $budget = $this->fuel->budgetFor($day);

        $spentToday = $this->fuel->spentBetween($day->copy()->startOfDay(), $day->copy()->endOfDay());

        return [
            'date' => $day->toDateString(),
            'daily_budget_cents' => $budget?->daily_budget_cents ?? 0,
            'spent_today_cents' => $spentToday,
            'pending_today_cents' => $this->fuel->pendingBetween($day->copy()->startOfDay(), $day->copy()->endOfDay()),
            'currency' => $budget?->currency ?? 'PHP',
            'projection_cents' => $this->monthProjection($day),
            'open_requests' => $this->fuel->openRequests(),
        ];
    }

    private function monthProjection(Carbon $day): int
    {
        $start = $day->copy()->startOfMonth();
        $spent = $this->fuel->spentBetween($start, $day->copy()->endOfDay());

        $elapsed = max(1, $start->diffInDays($day) + 1);

        return (int) round($spent / $elapsed * $day->daysInMonth);
    }
}
