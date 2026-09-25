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
            /**
             * What the truck's owner took out of the day.
             *
             * Zero on the fleet's own units and on one hired at a flat monthly
             * rent — in both, every peso of the income is the fleet's. Only a
             * revenue-share truck carries a figure here, and it is the same one
             * the owner's wallet was credited with.
             */
            'owner_share_cents' => $this->owner_share_cents,
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

            /**
             * Who the day's driver and helper salary belonged to.
             *
             * The names come along so the sheet reads as a sheet rather than as
             * a row of identifiers — and null is a real answer, meaning nobody
             * has said. An unattributed row is counted toward nobody's payslip,
             * which is worth being able to see before a pay run is built.
             */
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            // Each helper and what they were paid. `helper_salary_cents`
            // above is the sum of these salaries.
            'helpers' => $this->helpers
                ->map(static fn ($line): array => [
                    'driver_id' => $line->driver_id,
                    'name' => $line->driver?->name,
                    'salary_cents' => $line->salary_cents,
                ])
                ->all(),
            'customer' => $this->customer?->name,

            ...$this->stamps(),
        ];
    }
}
