<?php

declare(strict_types=1);

namespace App\Domain\Hr\Repositories;

use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Models\UndertimeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Both request tables, behind one repository.
 *
 * Not two, because every caller wants them together: the desk works one
 * "waiting on me" queue, the roster shows who is off today whichever way they
 * asked, and performance counts both against the same period. Two repositories
 * would mean every one of those doing the joining itself.
 *
 * Not extending `Repository` either — its `applyFilters` filters a `status`
 * column with the shared `StatusValue` vocabulary, and these two use
 * `RequestStatus`, where "approved" has no equivalent.
 */
class TimeOffRepository
{
    /* --------------------------------------------------------------- Leave */

    public function leaveQuery(): Builder
    {
        return LeaveRequest::query()
            ->with(['employee:id,employee_no,first_name,last_name,position,photo_path', 'decider:id,name'])
            ->orderByDesc('starts_on')
            ->orderByDesc('created_at');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function leave(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->applyFilters($this->leaveQuery(), $filters, 'starts_on', 'ends_on')
            ->paginate($perPage);
    }

    /**
     * Approved leave overlapping a window, for one employee or all of them.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function leaveBetween(Carbon $from, Carbon $to, ?string $employeeId = null): Collection
    {
        return LeaveRequest::query()
            ->counted()
            ->overlapping($from->toDateString(), $to->toDateString())
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->get();
    }

    /**
     * Does this employee already have leave booked across these dates?
     *
     * Cancelled and rejected requests are ignored — those are not leave. A
     * pending one still counts as a clash, because approving both would put
     * somebody on two leaves at once and nothing downstream would notice.
     */
    public function hasLeaveClash(string $employeeId, string $from, string $to, ?string $ignoreId = null): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->overlapping($from, $to)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /* ----------------------------------------------------------- Undertime */

    public function undertimeQuery(): Builder
    {
        return UndertimeRequest::query()
            ->with(['employee:id,employee_no,first_name,last_name,position,photo_path', 'decider:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('created_at');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function undertime(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->applyFilters($this->undertimeQuery(), $filters, 'date', 'date')
            ->paginate($perPage);
    }

    /** @return Collection<int, UndertimeRequest> */
    public function undertimeBetween(Carbon $from, Carbon $to, ?string $employeeId = null): Collection
    {
        return UndertimeRequest::query()
            ->counted()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->get();
    }

    /* --------------------------------------------------------------- Both */

    /** What is waiting on a decision. Drives the nav badge. */
    public function openCount(): int
    {
        return LeaveRequest::query()->open()->count()
            + UndertimeRequest::query()->open()->count();
    }

    /**
     * Who is off today.
     *
     * Built on the bare models with no eager loads or ordering: it is a count
     * behind a tile, and the roster it belongs to already has the names.
     *
     * @return string[] employee ids
     */
    public function awayToday(): array
    {
        $today = Carbon::now()->toDateString();

        return LeaveRequest::query()
            ->counted()
            ->overlapping($today, $today)
            ->pluck('employee_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, string $fromColumn, string $toColumn): Builder
    {
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->whereIn('type', (array) $filters['type']);
        }

        if (! empty($filters['open'])) {
            $query->open();
        }

        // A range asks "what overlaps this window", not "what starts in it" —
        // a leave running from last month into this one is this month's
        // problem too, and a start-date filter would hide it.
        if (! empty($filters['from'])) {
            $query->whereDate($toColumn, '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $query->whereDate($fromColumn, '<=', Carbon::parse($filters['to'])->toDateString());
        }

        return $query;
    }
}
