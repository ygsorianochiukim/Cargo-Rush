<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\Contract;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Contract */
class ContractResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,

            'pay_basis' => $this->pay_basis?->value,
            'pay_basis_label' => $this->pay_basis?->label(),
            'pay_basis_unit' => $this->pay_basis?->unit(),
            'amount_cents' => (int) $this->amount_cents,

            'tier' => $this->tier?->value,
            'tier_label' => $this->tier?->label(),

            'effective_from' => $this->effective_from?->toDateString(),
            'reason' => $this->reason,

            'summary' => $this->summary(),

            'has_started' => $this->effective_from?->isPast() ?? false,

            'author' => $this->whenLoaded('author', fn () => $this->author?->name),

            ...$this->stamps(),
        ];
    }
}
