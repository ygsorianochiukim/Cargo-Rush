<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayrollCutoffRequest;
use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;

/**
 * One request to move the pay cutoff, as the screen deciding it needs to see
 * it.
 *
 * The days are the least useful thing on here and they are not what an
 * administrator reads. What they read is **what it would mean**: the sentence,
 * the worked periods, and what the firm is on now — because approving this
 * changes the shape of every future pay period and "10, 25" is not something
 * anybody can picture well enough to say yes to.
 *
 * @mixin PayrollCutoffRequest
 */
class PayrollCutoffRequestResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $asked = $this->calendar();
        $pending = $this->isPending();

        return [
            'id' => $this->id,

            'cutoff_days' => $this->cutoff_days,
            /** What is being asked for, worked out. */
            'calendar' => $asked->toArray(),
            'summary' => $this->summary(),

            'payroll_deduct_on' => $this->payroll_deduct_on?->value,
            'payroll_deduct_on_label' => $this->payroll_deduct_on?->label(),

            'reason' => $this->reason,

            'status' => $this->status,
            'requested_by_name' => $this->requestedBy?->name,
            'decided_by_name' => $this->decidedBy?->name,
            'decided_at' => $this->iso($this->decided_at),
            'decision_note' => $this->decision_note,

            /**
             * What the firm was on when this was approved.
             *
             * Only on a settled request, because before then there is nothing
             * to record: the "before" of a pending request is whatever the
             * company is on at the moment somebody clicks, which is
             * `current_calendar` below and may still change.
             */
            'previous_cutoff_days' => $this->previous_cutoff_days,

            ...($pending ? $this->pendingContext() : []),

            ...$this->stamps(),
        ];
    }

    /**
     * The three things only a *pending* request needs to carry.
     *
     * All of them are about the moment of deciding rather than about the
     * request, which is why they are not stored on the row: they would be
     * stale by the time anybody read them back.
     *
     * @return array<string, mixed>
     */
    private function pendingContext(): array
    {
        $current = PayrollCalendar::for(app(Tenant::class)->company());

        return [
            /** What the firm is on right now, to compare against. */
            'current_calendar' => $current->toArray(),

            /**
             * Has somebody already made this change by hand?
             *
             * A real case: an office asks, an administrator changes the setting
             * directly, and the request sits there looking like outstanding
             * work. Saying so lets the screen offer "this is already in force"
             * rather than inviting a second, identical approval.
             */
            'already_in_force' => $current->days === array_map('intval', (array) $this->cutoff_days),

            /**
             * Why this cannot be approved *yet*, if it cannot.
             *
             * Almost always an open draft pay run. Checked when the screen is
             * drawn rather than only when the button is pressed, because
             * "approve" that 422s is a worse answer than a button that explains
             * itself before anybody touches it — and the reason is actionable:
             * approve or delete that run and this becomes available.
             */
            'blocked_reason' => PayrollCutoffDays::problemWith((array) $this->cutoff_days),
        ];
    }
}
