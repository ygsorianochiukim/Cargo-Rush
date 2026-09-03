<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\UndertimeRequest;
use App\Domain\Hr\Services\PhotoStore;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin UndertimeRequest
 */
class UndertimeRequestResource extends ApiResource
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
            'date' => $this->date?->toDateString(),
            // Trimmed to `H:i`: the column is a time and the seconds MySQL
            // returns are noise nobody typed.
            'from_time' => substr((string) $this->from_time, 0, 5),
            'to_time' => substr((string) $this->to_time, 0, 5),
            'hours' => $this->hours,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tone' => $this->status->tone()->value,
            'open' => $this->status->isOpen(),
            'decided_by' => $this->decided_by,
            'decided_by_name' => $this->decider?->name,
            'decided_at' => $this->iso($this->decided_at),
            'decision_note' => $this->decision_note,

            ...$this->stamps(),
        ];
    }
}
