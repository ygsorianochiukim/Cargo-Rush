<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLineTrip;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

class TripPayService
{
    public function __construct(private readonly HiredOutCrew $hiredOut) {}

    /** @return array{earned_cents: int, days: int, trips: int, trip_ids: list<string>} */
    public function forEmployee(Employee $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        return $this->forEmployees(collect([$employee]), $periodStart, $periodEnd)[$employee->getKey()]
            ?? ['earned_cents' => 0, 'days' => 0, 'trips' => 0, 'trip_ids' => []];
    }

    /**
     * @param  Collection<int, Employee>|SupportCollection<int, Employee>  $employees
     * @return array<string, array{earned_cents: int, days: int, trips: int, trip_ids: list<string>}>
     */
    public function forEmployees($employees, Carbon $periodStart, Carbon $periodEnd): array
    {
        $byDriver = [];

        foreach ($employees as $employee) {
            if ($employee->driver_id !== null) {
                $byDriver[$employee->driver_id] = $employee->getKey();
            }
        }

        $totals = [];

        foreach ($employees as $employee) {
            $totals[$employee->getKey()] = ['earned_cents' => 0, 'days' => [], 'trips' => 0, 'trip_ids' => []];
        }

        if ($byDriver === []) {
            return $this->countDays($totals);
        }

        $driverIds = array_keys($byDriver);

        $floor = $this->floor($periodStart);
        $hired = $this->hiredOut->spansFor($driverIds, $floor ?? $periodStart, $periodEnd);

        $this->addSheet($totals, $byDriver, $driverIds, $periodStart, $periodEnd, $hired);
        $this->addTrips($totals, $byDriver, $driverIds, $periodStart, $periodEnd, $floor, $hired);

        return $this->countDays($totals);
    }

    private function floor(Carbon $periodStart): ?Carbon
    {
        $floor = PayRun::query()
            ->where('links_trips', true)
            ->whereIn('status', [PayRun::APPROVED, PayRun::PAID])
            ->min('period_start');

        if ($floor === null) {
            return null;
        }

        $floor = Carbon::parse($floor)->startOfDay();

        return $floor->lt($periodStart->copy()->startOfDay()) ? $floor : null;
    }

    /**
     * @param  array<string, array{earned_cents: int, days: array<string, bool>, trips: int, trip_ids: list<string>}>  $totals
     * @param  array<string, string>  $byDriver
     * @param  string[]  $driverIds
     * @param  array<string, list<array{from: Carbon, to: Carbon}>>  $hired
     */
    private function addSheet(array &$totals, array $byDriver, array $driverIds, Carbon $periodStart, Carbon $periodEnd, array $hired): void
    {
        $rows = LedgerEntry::query()
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($driverIds): void {
                $query->whereIn('driver_id', $driverIds)
                    ->orWhereHas('helpers', static fn ($line) => $line->whereIn('driver_id', $driverIds));
            })
            ->with('helpers:id,ledger_entry_id,driver_id,salary_cents')
            ->get(['id', 'date', 'driver_id', 'driver_salary_cents']);

        foreach ($rows as $row) {
            $day = $row->date->toDateString();

            $seats = [
                [$row->driver_id, (int) $row->driver_salary_cents],
                ...$row->helpers->map(static fn ($line): array => [$line->driver_id, (int) $line->salary_cents])->all(),
            ];

            foreach ($seats as [$driverId, $amount]) {
                $employeeId = $driverId === null ? null : ($byDriver[$driverId] ?? null);

                if ($employeeId === null || HiredOutCrew::covers($hired[$driverId] ?? [], $row->date)) {
                    continue;
                }

                $totals[$employeeId]['earned_cents'] += $amount;
                $totals[$employeeId]['days'][$day] = true;
            }
        }
    }

    /**
     * @param  array<string, array{earned_cents: int, days: array<string, bool>, trips: int, trip_ids: list<string>}>  $totals
     * @param  array<string, string>  $byDriver
     * @param  string[]  $driverIds
     * @param  array<string, list<array{from: Carbon, to: Carbon}>>  $hired
     */
    private function addTrips(array &$totals, array $byDriver, array $driverIds, Carbon $periodStart, Carbon $periodEnd, ?Carbon $floor, array $hired): void
    {
        $from = $periodStart->copy()->startOfDay();
        $to = $periodEnd->copy()->endOfDay();

        $trips = $this->deliveredBetween($driverIds, $from, $to);

        if ($floor !== null) {
            $trips = $trips->merge(
                $this->deliveredBetween($driverIds, $floor, $from->copy()->subSecond()),
            )->unique('id');
        }

        $paid = [];

        PayRunLineTrip::query()
            ->settled()
            ->whereIn('employee_id', array_values(array_unique($byDriver)))
            ->whereIn('trip_id', $trips->modelKeys())
            ->get(['employee_id', 'trip_id'])
            ->each(function (PayRunLineTrip $link) use (&$paid): void {
                $paid[$link->employee_id][$link->trip_id] = true;
            });

        foreach ($trips as $trip) {
            $day = Carbon::parse($trip->deliveryLog?->delivered_at ?? $trip->scheduled_at);

            foreach (array_unique([$trip->driver_id, ...$trip->helperIds()]) as $driverId) {
                $employeeId = $driverId === null ? null : ($byDriver[$driverId] ?? null);

                if ($employeeId === null || isset($paid[$employeeId][$trip->getKey()])) {
                    continue;
                }

                if (HiredOutCrew::covers($hired[$driverId] ?? [], $day)) {
                    continue;
                }

                $totals[$employeeId]['trips']++;
                $totals[$employeeId]['trip_ids'][] = $trip->getKey();
            }
        }
    }

    /**
     * @param  string[]  $driverIds
     * @return Collection<int, Trip>
     */
    private function deliveredBetween(array $driverIds, Carbon $from, Carbon $to): Collection
    {
        return Trip::query()
            ->where('status', StatusValue::Delivered->value)
            ->where(function ($query) use ($driverIds): void {
                $query->whereIn('driver_id', $driverIds)
                    ->orWhereHas('helpers', static fn ($helper) => $helper->whereIn('drivers.id', $driverIds));
            })
            ->where(function ($query) use ($from, $to): void {
                $query->whereHas('deliveryLog', function ($log) use ($from, $to): void {
                    $log->whereBetween('delivered_at', [$from, $to]);
                });

                $query->orWhere(function ($noProof) use ($from, $to): void {
                    $noProof->whereDoesntHave('deliveryLog')
                        ->whereBetween('scheduled_at', [$from, $to]);
                });
            })
            ->with(['helpers:drivers.id', 'deliveryLog:id,trip_id,delivered_at'])
            ->get(['id', 'driver_id', 'scheduled_at']);
    }

    /**
     * @param  array<string, array{earned_cents: int, days: array<string, bool>, trips: int, trip_ids: list<string>}>  $totals
     * @return array<string, array{earned_cents: int, days: int, trips: int, trip_ids: list<string>}>
     */
    private function countDays(array $totals): array
    {
        return array_map(
            static fn (array $row): array => [
                'earned_cents' => $row['earned_cents'],
                'days' => count($row['days']),
                'trips' => $row['trips'],
                'trip_ids' => $row['trip_ids'],
            ],
            $totals,
        );
    }
}
