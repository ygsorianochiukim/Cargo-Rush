<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Services;

use App\Domain\Delivery\Models\DeliveryLog;
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

    /** @return array<int, array<string, mixed>> */
    public function tripFuel(): array
    {
        return $this->fuel->tripFuel()
            ->map(static fn (DeliveryLog $log): array => [
                'id' => $log->id,
                'trip_id' => $log->trip_id,
                'reference' => $log->trip?->reference,
                'route' => $log->trip === null ? null : "{$log->trip->origin} → {$log->trip->destination}",
                'vehicle_plate' => $log->trip?->vehicle?->plate,
                'driver_name' => $log->trip?->driver?->name,
                'litres' => $log->fuel_litres,
                'amount_cents' => (int) $log->posted_fuel_cents,
                'currency' => $log->trip?->currency ?? 'PHP',
                'delivered_at' => $log->delivered_at?->toIso8601String(),
                'posted_on' => $log->posted_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function budget(?Carbon $on = null): array
    {
        $day = $on ?? now();
        $budget = $this->fuel->budgetFor($day);

        $tripToday = $this->fuel->tripFuelBetween($day, $day);
        $spentToday = $this->fuel->spentBetween($day->copy()->startOfDay(), $day->copy()->endOfDay()) + $tripToday;

        return [
            'date' => $day->toDateString(),
            'daily_budget_cents' => $budget?->daily_budget_cents ?? 0,
            'spent_today_cents' => $spentToday,
            'trip_fuel_today_cents' => $tripToday,
            'trip_fuel_month_cents' => $this->fuel->tripFuelBetween($day->copy()->startOfMonth(), $day),
            'pending_today_cents' => $this->fuel->pendingBetween($day->copy()->startOfDay(), $day->copy()->endOfDay()),
            'currency' => $budget?->currency ?? 'PHP',
            'projection_cents' => $this->monthProjection($day),
            'open_requests' => $this->fuel->openRequests(),
        ];
    }

    private function monthProjection(Carbon $day): int
    {
        $start = $day->copy()->startOfMonth();
        $spent = $this->fuel->spentBetween($start, $day->copy()->endOfDay())
            + $this->fuel->tripFuelBetween($start, $day);

        $elapsed = max(1, $start->diffInDays($day) + 1);

        return (int) round($spent / $elapsed * $day->daysInMonth);
    }
}
