<?php

declare(strict_types=1);

namespace App\Domain\Billing\Resources;

use App\Domain\Billing\Models\Payment;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Payment */
class PaymentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'direction' => $this->direction->value,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,

            'paid_on' => $this->paid_on?->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,

            'allocated_cents' => $this->allocatedCents(),
            'unallocated_cents' => $this->unallocatedCents(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(
                fn ($allocation): array => [
                    'invoice_id' => $allocation->invoice_id,
                    'invoice_number' => $allocation->invoice?->number,
                    'amount_cents' => $allocation->amount_cents,
                ],
            )),

            'recorded_by' => $this->recordedBy?->name,

            ...$this->stamps(),
        ];
    }
}
