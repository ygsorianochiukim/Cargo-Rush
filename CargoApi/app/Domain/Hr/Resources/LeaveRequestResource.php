<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Services\PhotoStore;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->fullName(),
            'employee_no' => $this->employee?->employee_no,
            'employee_position' => $this->employee?->position,
            'employee_photo_url' => app(PhotoStore::class)->url($this->employee?->photo_path),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'paid' => $this->type->isPaid(),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // The tone, not a colour (DESIGN.md section 7.1).
            'tone' => $this->status->tone()->value,
            'open' => $this->status->isOpen(),
            // Who decided and when, not just the outcome — "who approved it?"
            // is half of what this module is asked.
            'decided_by' => $this->decided_by,
            'decided_by_name' => $this->decider?->name,
            'decided_at' => $this->iso($this->decided_at),
            'decision_note' => $this->decision_note,
            'active_today' => $this->coversToday(),

            ...$this->stamps(),
        ];
    }
}
