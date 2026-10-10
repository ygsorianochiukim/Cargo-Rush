<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Models\UndertimeRequest;
use App\Domain\Hr\Repositories\TimeOffRepository;
use App\Domain\Shared\Enums\LeaveType;
use App\Domain\Shared\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TimeOffService
{
    public function __construct(private readonly TimeOffRepository $timeOff) {}

    /** @param array<string, mixed> $attributes */
    public function requestLeave(Employee $employee, array $attributes): LeaveRequest
    {
        $from = Carbon::parse($attributes['starts_on']);
        $to = Carbon::parse($attributes['ends_on']);

        abort_if(
            $to->lessThan($from),
            422,
            'Leave cannot end before it starts.',
        );

        abort_if(
            $this->timeOff->hasLeaveClash($employee->id, $from->toDateString(), $to->toDateString()),
            422,
            'This overlaps leave already booked for this employee.',
        );

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => LeaveType::from((string) $attributes['type'])->value,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'days' => LeaveRequest::daysBetween($from, $to),
            'reason' => (string) $attributes['reason'],
            'status' => RequestStatus::Pending->value,
        ])->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function updateLeave(LeaveRequest $leave, array $attributes): LeaveRequest
    {
        $this->assertPending($leave);

        $from = Carbon::parse($attributes['starts_on'] ?? $leave->starts_on);
        $to = Carbon::parse($attributes['ends_on'] ?? $leave->ends_on);

        abort_if($to->lessThan($from), 422, 'Leave cannot end before it starts.');

        abort_if(
            $this->timeOff->hasLeaveClash(
                $leave->employee_id,
                $from->toDateString(),
                $to->toDateString(),
                ignoreId: $leave->id,
            ),
            422,
            'This overlaps leave already booked for this employee.',
        );

        $leave->update([
            ...array_intersect_key($attributes, array_flip(['type', 'reason'])),
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'days' => LeaveRequest::daysBetween($from, $to),
        ]);

        return $leave->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function requestUndertime(Employee $employee, array $attributes): UndertimeRequest
    {
        $hours = UndertimeRequest::hoursBetween(
            (string) $attributes['from_time'],
            (string) $attributes['to_time'],
        );

        abort_if($hours <= 0, 422, 'The end time has to be later than the start time.');

        return UndertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => Carbon::parse($attributes['date'])->toDateString(),
            'from_time' => $attributes['from_time'],
            'to_time' => $attributes['to_time'],
            'hours' => $hours,
            'reason' => (string) $attributes['reason'],
            'status' => RequestStatus::Pending->value,
        ])->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function updateUndertime(UndertimeRequest $undertime, array $attributes): UndertimeRequest
    {
        $this->assertPending($undertime);

        $from = (string) ($attributes['from_time'] ?? $undertime->from_time);
        $to = (string) ($attributes['to_time'] ?? $undertime->to_time);
        $hours = UndertimeRequest::hoursBetween($from, $to);

        abort_if($hours <= 0, 422, 'The end time has to be later than the start time.');

        $undertime->update([
            ...array_intersect_key($attributes, array_flip(['reason'])),
            'date' => Carbon::parse($attributes['date'] ?? $undertime->date)->toDateString(),
            'from_time' => $from,
            'to_time' => $to,
            'hours' => $hours,
        ]);

        return $undertime->refresh();
    }

    /**
     * @template T of LeaveRequest|UndertimeRequest
     *
     * @param  T  $request
     * @return T
     */
    public function decide(
        LeaveRequest|UndertimeRequest $request,
        RequestStatus $decision,
        ?int $userId,
        ?string $note = null,
    ): LeaveRequest|UndertimeRequest {
        $this->assertPending($request);

        abort_if(
            ! in_array($decision, [RequestStatus::Approved, RequestStatus::Rejected], true),
            422,
            'A decision is either approved or rejected.',
        );

        $request->update([
            'status' => $decision->value,
            'decided_by' => $userId,
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
        ]);

        return $request->refresh();
    }

    /**
     * @template T of LeaveRequest|UndertimeRequest
     *
     * @param  T  $request
     * @return T
     */
    public function withdraw(LeaveRequest|UndertimeRequest $request): LeaveRequest|UndertimeRequest
    {
        abort_if(
            $request->status === RequestStatus::Rejected,
            422,
            'This request was already rejected, so there is nothing to withdraw.',
        );

        abort_if(
            $request->status === RequestStatus::Cancelled,
            422,
            'This request has already been withdrawn.',
        );

        $request->update(['status' => RequestStatus::Cancelled->value]);

        return $request->refresh();
    }

    private function assertPending(Model $request): void
    {
        abort_if(
            $request->status !== RequestStatus::Pending,
            422,
            'This request has already been decided. Raise a new one instead of changing it.',
        );
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $awayToday = $this->timeOff->awayToday();

        return [
            'awaiting_decision' => $this->timeOff->openCount(),
            'away_today' => count($awayToday),
            'away_employee_ids' => $awayToday,
            'leave_types' => array_map(
                static fn (LeaveType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'paid' => $type->isPaid(),
                ],
                LeaveType::cases(),
            ),
        ];
    }
}
