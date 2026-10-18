<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Resources;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin PaymentSource */
class PaymentSourceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $counted = array_key_exists('expense_spend_cents', $this->resource->getAttributes());

        $expense = (int) ($this->expense_spend_cents ?? 0);
        $fuel = (int) ($this->fuel_spend_cents ?? 0) + (int) ($this->trip_fuel_spend_cents ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'provider' => $this->provider,
            'last_four' => $this->last_four,
            'note' => $this->note,
            'status' => $this->status?->value,

            'expense_spend_cents' => $counted ? $expense : null,
            'fuel_spend_cents' => $counted ? $fuel : null,
            'spend_cents' => $counted ? $expense + $fuel : null,

            ...$this->stamps(),
        ];
    }
}
