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

/**
 * How somebody is doing, over a period.
 *
 * Derived, never stored. The moment a performance figure becomes a column it
 * starts disagreeing with the trips underneath it — a trip gets reassigned, a
 * delivery is backdated, an incident is filed a week late — and nobody can say
 * which number is right. So this recomputes from the operational record every
 * time, and the cost of that is one query per employee per period.
 *
 * Two things it deliberately does not do.
 *
 * It does not score. There is no weighted "performance out of 100" here,
 * because the weights would be invented by a developer and then used to decide
 * somebody's job. It reports the figures a supervisor already reasons with —
 * runs done, share on time, incidents, days away — and leaves the judgement
 * where it belongs.
 *
 * And it does not treat a non-driver as a zero. An office clerk has no trips
 * and that is not a bad score; `drives` says whether the road figures mean
 * anything at all for this person, and the clients render the two cases
 * differently rather than showing a mechanic as the worst driver on the fleet.
 */
class PerformanceService
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly TripRepository $trips,
        private readonly IncidentRepository $incidents,
        private readonly TimeOffRepository $timeOff,
    ) {}

    /**
     * One person's figures for a window.
     *
     * @return array<string, mixed>
     */
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
            // Whether the road figures mean anything for this person at all.
            'drives' => $employee->driver_id !== null,
            ...$road,
            'incidents' => $employee->driver_id === null
                ? 0
                : $this->incidents->countForDriverBetween($employee->driver_id, $from, $to),
            // Only approved requests count. Counting a pending one would
            // penalise somebody for asking.
            'leave_days' => round((float) $leave->sum('days'), 1),
            'leave_requests' => $leave->count(),
            'undertime_hours' => round((float) $undertime->sum('hours'), 2),
            'undertime_requests' => $undertime->count(),
        ];
    }

    /**
     * The roster ranked by runs completed.
     *
     * Only the people who drive: a leaderboard listing the whole office with
     * eight zeroes at the bottom is a worse answer than a shorter list.
     *
     * @return array<int, array<string, mixed>>
     */
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
     * The fleet's totals for the period, for the tiles above the table.
     *
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
            // Null rather than 100%: nothing delivered is not a perfect record,
            // and a green figure over an empty month is a lie.
            'on_time_rate' => $completed === 0 ? null : round($onTime / $completed, 4),
            'incidents' => (int) array_sum(array_column($rows, 'incidents')),
            'distance_km' => (int) array_sum(array_column($rows, 'distance_km')),
            'revenue_cents' => (int) array_sum(array_column($rows, 'revenue_cents')),
            'leave_days' => round((float) array_sum(array_column($rows, 'leave_days')), 1),
            'undertime_hours' => round((float) array_sum(array_column($rows, 'undertime_hours')), 2),
            'currency' => 'PHP',
        ];
    }

    /**
     * The road figures for one crew member.
     *
     * @return array<string, mixed>
     */
    private function roadWork(string $driverId, Carbon $from, Carbon $to): array
    {
        /** @var Collection<int, Trip> $trips */
        $trips = $this->trips->forCrewBetween($driverId, $from, $to);

        $delivered = $trips->filter(
            static fn (Trip $trip): bool => $trip->status === StatusValue::Delivered,
        );

        $onTime = $delivered->filter(fn (Trip $trip): bool => $this->wasOnTime($trip));

        // Cancelled runs are excluded from the denominator: a customer calling
        // off a booking is not the driver failing to complete it.
        $completable = $trips->reject(
            static fn (Trip $trip): bool => $trip->status === StatusValue::Cancelled,
        );

        return [
            'trips_assigned' => $trips->count(),
            'trips_completed' => $delivered->count(),
            'trips_cancelled' => $trips->count() - $completable->count(),
            'trips_on_time' => $onTime->count(),
            // Null when there is nothing to divide by, in both cases, so a
            // quiet month shows "—" rather than a zero that reads as a failure.
            'on_time_rate' => $delivered->isEmpty() ? null : round($onTime->count() / $delivered->count(), 4),
            'completion_rate' => $completable->isEmpty()
                ? null
                : round($delivered->count() / $completable->count(), 4),
            'distance_km' => (int) round($delivered->sum('distance_total_m') / 1000),
            // What their completed runs earned the business. Not a commission
            // and not their pay — the figure a supervisor wants beside a count.
            'revenue_cents' => (int) $delivered->sum('price_cents'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * Did this run arrive by the time it promised?
     *
     * A trip with no ETA counts as on time, and that is a deliberate choice
     * rather than an oversight. Plenty of work is booked over the phone with no
     * promised arrival; marking those late would punish a driver for what the
     * desk did not enter. A delivered trip with no delivery log has no arrival
     * time to judge, and is treated the same way.
     */
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
