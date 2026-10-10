<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Shared\Enums\PayComponentBasis;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin EmployeePayComponent */
class EmployeePayComponentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $component = $this->component;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'pay_component_id' => $this->pay_component_id,

            'component' => $component === null ? null : new PayComponentResource($component),

            'amount_cents' => $this->amount_cents,
            'rate_bp' => $this->rate_bp,

            'effective_amount_cents' => $component?->basis === PayComponentBasis::Fixed
                ? ($this->amount_cents ?? (int) $component->amount_cents)
                : null,
            'effective_rate_bp' => $component?->basis === PayComponentBasis::PercentOfBasic
                ? ($this->rate_bp ?? (int) $component->rate_bp)
                : null,
            'overrides_amount' => $this->amount_cents !== null || $this->rate_bp !== null,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),

            'status' => $this->status?->value,
            'notes' => $this->notes,
            'currency' => 'PHP',

            ...$this->stamps(),
        ];
    }
}
