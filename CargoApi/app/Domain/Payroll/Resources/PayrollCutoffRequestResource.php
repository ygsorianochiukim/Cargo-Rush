<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayrollCutoffRequest;
use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;

/** @mixin PayrollCutoffRequest */
class PayrollCutoffRequestResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $asked = $this->calendar();
        $pending = $this->isPending();

        return [
            'id' => $this->id,

            'cutoff_days' => $this->cutoff_days,
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

            'previous_cutoff_days' => $this->previous_cutoff_days,

            ...($pending ? $this->pendingContext() : []),

            ...$this->stamps(),
        ];
    }

    /** @return array<string, mixed> */
    private function pendingContext(): array
    {
        $current = PayrollCalendar::for(app(Tenant::class)->company());

        return [
            'current_calendar' => $current->toArray(),

            'already_in_force' => $current->days === array_map('intval', (array) $this->cutoff_days),

            'blocked_reason' => PayrollCutoffDays::problemWith((array) $this->cutoff_days),
        ];
    }
}
