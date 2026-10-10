<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin PayComponent */
class PayComponentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'sign' => $this->kind->sign(),

            'basis' => $this->basis->value,
            'basis_label' => $this->basis->label(),
            'amount_cents' => $this->amount_cents,
            'rate_bp' => $this->rate_bp,

            'schedule' => $this->schedule->value,
            'schedule_label' => $this->schedule->label(),
            'schedule_detail' => $this->schedule->detail(),
            'is_monthly' => $this->schedule->isMonthly(),

            'taxable' => $this->isTaxable(),

            'status' => $this->status?->value,
            'position' => $this->position,
            'notes' => $this->notes,
            'currency' => 'PHP',

            'assignment_count' => $this->whenCounted('assignments'),

            ...$this->stamps(),
        ];
    }
}
