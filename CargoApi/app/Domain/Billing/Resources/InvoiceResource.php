<?php

declare(strict_types=1);

namespace App\Domain\Billing\Resources;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Invoice */
class InvoiceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'customer' => $this->counterparty(),
            'customer_id' => $this->customer_id,
            'payee' => $this->payee,
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),

            'net_amount_cents' => $this->net_amount_cents,
            'vat_cents' => $this->vat_cents,
            'amount_cents' => $this->amount_cents,

            'taxable_base_cents' => $this->taxBaseCents(),
            'withholding_cents' => $this->withholding_cents,
            'due_cents' => $this->dueCents(),

            'paid_cents' => $this->paidCents(),
            'balance_cents' => $this->balanceCents(),

            'vat_rate_bp' => $this->vat_rate_bp,
            'withholding_rate_bp' => $this->withholding_rate_bp,
            'vat_treatment' => $this->vat_treatment?->value,
            'vat_label' => $this->vat_treatment?->label(),

            'currency' => $this->currency,
            'trip_id' => $this->trip_id,
            'trip_reference' => $this->trip?->reference,
            'direction' => $this->direction->value,
            'status' => $this->status->value,
            'paid_at' => $this->iso($this->paid_at),

            ...$this->stamps(),
        ];
    }
}
