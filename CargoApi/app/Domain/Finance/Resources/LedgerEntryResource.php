<?php

declare(strict_types=1);

namespace App\Domain\Finance\Resources;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * A ledger row, with its two derived figures alongside.
 *
 * They are sent because every client would otherwise compute them, but they
 * are computed from the same five columns the row carries — so a client can
 * check the arithmetic rather than having to trust it.
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'truck_id' => $this->truck_id,
            'truck_label' => $this->truck?->label,
            'truck_plate' => $this->truck?->plate,
            'date' => $this->date?->toDateString(),
            'trip_income_cents' => $this->trip_income_cents,
            'fuel_cents' => $this->fuel_cents,
            'driver_salary_cents' => $this->driver_salary_cents,
            'helper_salary_cents' => $this->helper_salary_cents,
            'maintenance_cents' => $this->maintenance_cents,
            'allowance_cents' => $this->allowance_cents,
            'total_expenses_cents' => $this->totalExpensesCents(),
            'net_income_cents' => $this->netIncomeCents(),
            'currency' => 'PHP',
            'route' => $this->route,
            'remarks' => $this->remarks,

            // The trip that opened the row, so Monitoring can show which run
            // a day came from. The reference is what goes over the wire, not
            // the id — it is the only trip identity a human reads
            // (DESIGN.md section 5.3). Null for a row entered by hand.
            'trip_id' => $this->trip_id,
            'trip_reference' => $this->trip?->reference,

            // Whose work the day was, where it was one customer's. Null is an
            // ordinary answer, not a gap — see the relation for why.
            'customer_id' => $this->customer_id,
            'customer' => $this->customer?->name,

            ...$this->stamps(),
        ];
    }
}
