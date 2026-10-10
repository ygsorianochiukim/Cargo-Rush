<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Position */
class PositionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,

            'drives' => (bool) $this->drives,

            'pay_basis' => $this->pay_basis?->value,
            'pay_basis_label' => $this->pay_basis?->label(),
            'pay_basis_unit' => $this->pay_basis?->unit(),
            'trainee_amount_cents' => (int) $this->trainee_amount_cents,
            'probationary_amount_cents' => (int) $this->probationary_amount_cents,
            'regular_amount_cents' => (int) $this->regular_amount_cents,
            'has_rate_card' => $this->hasRateCard(),

            'pay_summary' => $this->paySummary(),

            'position' => $this->position,
            'status' => $this->status->value,
            'employee_count' => $this->whenCounted('employees'),

            ...$this->stamps(),
        ];
    }
}
