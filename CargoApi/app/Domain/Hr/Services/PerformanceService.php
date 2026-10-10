<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Repositories\EmployeeRepository;
use App\Domain\Hr\Repositories\TimeOffRepository;
use App\Domain\Incident\Repositories\IncidentRepository;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PerformanceService
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly TripRepository $trips,
        private readonly IncidentRepository $incidents,
        private readonly TimeOffRepository $timeOff,
    ) {}

    /** @return array<string, mixed> */
    public function forEmployee(Employee $employee, Carbon $from, Carbon $to): array
    {
        $road = $employee->driver_id === null
            ? $this->noRoadWork()
            : $this->roadWork($employee->driver_id, $from, $to);

        $leave = $this->timeOff->leaveBetween($from, $to, $employee->id);
        $undertime = $this->timeOff->undertimeBetween($from, $to, $employee->id);

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->fullName(),
                'position' => $employee->position,
                'photo_url' => app(PhotoStore::class)->url($employee->photo_path),
            ],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'drives' => $employee->driver_id !== null,
            ...$road,
            'incidents' => $employee->driver_id === null
                ? 0
                : $this->incidents->countForDriverBetween($employee->driver_id, $from, $to),
            'leave_days' => round((float) $leave->sum('days'), 1),
            'leave_requests' => $leave->count(),
            'undertime_hours' => round((float) $undertime->sum('hours'), 2),
            'undertime_requests' => $undertime->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function leaderboard(Carbon $from, Carbon $to, int $limit = 20): array
    {
        $rows = $this->employees->all(['status' => StatusValue::Active->value])
            ->filter(fn (Employee $employee): bool => $employee->driver_id !== null)
            ->map(fn (Employee $employee): array => $this->forEmployee($employee, $from, $to))
            ->filter(static fn (array $row): bool => $row['trips_assigned'] > 0)
            ->sortByDesc('trips_completed')
            ->take($limit)
            ->values()
            ->all();

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function totals(array $rows): array
    {
        $completed = (int) array_sum(array_column($rows, 'trips_completed'));
        $onTime = (int) array_sum(array_column($rows, 'trips_on_time'));

        return [
            'crew' => count($rows),
            'trips_completed' => $completed,
            'trips_on_time' => $onTime,
            'on_time_rate' => $completed === 0 ? null : round($onTime / $completed, 4),
            'incidents' => (int) array_sum(array_column($rows, 'incidents')),
            'distance_km' => (int) array_sum(array_column($rows, 'distance_km')),
            'revenue_cents' => (int) array_sum(array_column($rows, 'revenue_cents')),
            'leave_days' => round((float) array_sum(array_column($rows, 'leave_days')), 1),
            'undertime_hours' => round((float) array_sum(array_column($rows, 'undertime_hours')), 2),
            'currency' => 'PHP',
        ];
    }

    /** @return array<string, mixed> */
    private function roadWork(string $driverId, Carbon $from, Carbon $to): array
    {
        /** @var Collection<int, Trip> $trips */
        $trips = $this->trips->forCrewBetween($driverId, $from, $to);

        $delivered = $trips->filter(
            static fn (Trip $trip): bool => $trip->status === StatusValue::Delivered,
        );

        $onTime = $delivered->filter(fn (Trip $trip): bool => $this->wasOnTime($trip));

        $completable = $trips->reject(
            static fn (Trip $trip): bool => $trip->status === StatusValue::Cancelled,
        );

        return [
            'trips_assigned' => $trips->count(),
            'trips_completed' => $delivered->count(),
            'trips_cancelled' => $trips->count() - $completable->count(),
            'trips_on_time' => $onTime->count(),
            'on_time_rate' => $delivered->isEmpty() ? null : round($onTime->count() / $delivered->count(), 4),
            'completion_rate' => $completable->isEmpty()
                ? null
                : round($delivered->count() / $completable->count(), 4),
            'distance_km' => (int) round($delivered->sum('distance_total_m') / 1000),
            'revenue_cents' => (int) $delivered->sum('price_cents'),
        ];
    }

    /** @return array<string, mixed> */
    private function noRoadWork(): array
    {
        return [
            'trips_assigned' => 0,
            'trips_completed' => 0,
            'trips_cancelled' => 0,
            'trips_on_time' => 0,
            'on_time_rate' => null,
            'completion_rate' => null,
            'distance_km' => 0,
            'revenue_cents' => 0,
        ];
    }

    private function wasOnTime(Trip $trip): bool
    {
        $promised = $trip->eta;
        $arrived = $trip->deliveryLog?->delivered_at;

        if ($promised === null || $arrived === null) {
            return true;
        }

        return $arrived->lessThanOrEqualTo($promised);
    }
}
