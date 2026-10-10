<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trucker\Models\WalletEntry;
use Illuminate\Http\Request;

/** @mixin WalletEntry */
class WalletEntryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'description' => $this->describe(),

            'amount_cents' => $this->amount_cents,

            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),

            'gross_cents' => $this->gross_cents,
            'rate_bp' => $this->rate_bp,

            'trip_id' => $this->trip_id,
            'trip_reference' => $this->whenLoaded('trip', fn () => $this->trip?->reference),

            'reference' => $this->reference,
            'note' => $this->note,
            'occurred_on' => $this->occurred_on?->toDateString(),

            'settleable' => $this->kind->isFromWork(),
            'settled' => $this->isSettled(),
            'settled_at' => $this->iso($this->settled_at),
            'settlement_reference' => $this->whenLoaded(
                'settlement',
                fn () => $this->settlement?->reference,
            ),

            'payment_state' => $this->paymentState(),

            'status' => $this->status->value,
            'method' => $this->method,

            ...$this->stamps(),
        ];
    }
}
