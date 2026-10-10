<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Resources\InvoiceResource;
use Illuminate\Http\Request;

/** @mixin Invoice */
class PortalInvoiceResource extends InvoiceResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'carrier_id' => $this->company_id,
            'carrier' => $this->company?->name,

            'trip_reference' => $this->trip?->reference,
            'trip_origin' => $this->trip?->origin,
            'trip_destination' => $this->trip?->destination,
            'trip_cargo' => $this->trip?->cargo,
            'trip_weight_kg' => $this->trip?->weight_kg,
            'trip_status' => $this->trip?->status->value,
            'requested_at' => $this->iso($this->trip?->scheduled_at ?? $this->trip?->created_at ?? $this->issued_at),

            'payments' => $this->allocations
                ->sortBy(fn (PaymentAllocation $allocation) => (string) $allocation->payment?->paid_on)
                ->values()
                ->map(fn (PaymentAllocation $allocation): array => [
                    'paid_on' => $allocation->payment?->paid_on?->toDateString(),
                    'method' => $allocation->payment?->method,
                    'method_label' => $this->methodLabel($allocation->payment?->method),
                    'reference' => $allocation->payment?->reference,
                    'amount_cents' => (int) $allocation->amount_cents,
                ])
                ->all(),
        ];
    }

    private function methodLabel(?string $method): ?string
    {
        return $method === null ? null : ucfirst(str_replace('_', ' ', $method));
    }
}
