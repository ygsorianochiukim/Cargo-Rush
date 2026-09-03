<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Services\PhotoStore;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Applicant
 */
class ApplicantResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $files = app(PhotoStore::class);

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'position_applied' => $this->position_applied,
            'contact' => $this->contact,
            'email' => $this->email,
            'address' => $this->address,
            'source' => $this->source,
            'applied_on' => $this->applied_on?->toDateString(),
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            // The tone, not a colour. A hex value never crosses the wire
            // (DESIGN.md section 7.1).
            'tone' => $this->stage->tone()->value,
            'open' => $this->stage->isOpen(),
            'photo_url' => $files->url($this->photo_path),
            'resume_url' => $files->url($this->resume_path),
            'rating' => $this->rating,
            'notes' => $this->notes,
            'employee_id' => $this->employee_id,
            'employee_no' => $this->employee?->employee_no,
            'decided_at' => $this->iso($this->decided_at),

            ...$this->stamps(),
        ];
    }
}
