<?php

declare(strict_types=1);

namespace App\Domain\Billing\Resources;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Invoice
 */
class InvoiceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            // One column in the UI whichever way the money points.
            'customer' => $this->counterparty(),
            'customer_id' => $this->customer_id,
            'payee' => $this->payee,
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'trip_id' => $this->trip_id,
            // Printed beside the amount, so an invoice raised by a delivery
            // can be reconciled against the run without a human matching
            // dates and figures by eye.
            'trip_reference' => $this->trip?->reference,
            'direction' => $this->direction->value,
            'status' => $this->status->value,
            // When the money arrived, as against when the document was
            // issued. `updated_at` would move on any later correction.
            'paid_at' => $this->iso($this->paid_at),

            ...$this->stamps(),
        ];
    }
}
