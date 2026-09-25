<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Http\Request;

/**
 * @mixin MaintenanceJob
 */
class MaintenanceJobResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,
            'kind' => $this->kind,
            'due_at' => $this->due_at?->toDateString(),
            'odometer_km' => $this->vehicle?->odometer_km ?? 0,
            'next_service_km' => $this->next_service_km,
            'status' => $this->status->value,

            /**
             * What it came to, when it was done and who did it.
             *
             * `cost_cents` is null while the job is only booked, and null is
             * not zero: zero is a warranty replacement somebody was not charged
             * for, and a screen that showed both as "₱0" would be hiding the
             * difference between "free" and "nobody has told us yet".
             */
            'cost_cents' => $this->cost_cents,
            'completed_on' => $this->completed_on?->toDateString(),
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'reference' => $this->reference,
            'note' => $this->note,

            /**
             * Whether the cost has reached the unit's daily sheet.
             *
             * A costed job with no `completed_on` is charged to nothing — there
             * is no day to charge it to — and this is what lets the screen say
             * so rather than leaving somebody to wonder why Profitability has
             * not moved. See `MaintenanceService`.
             */
            'on_the_sheet' => (int) $this->posted_cents !== 0,

            ...$this->stamps(),
        ];
    }
}
