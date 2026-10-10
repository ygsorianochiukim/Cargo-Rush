<?php

declare(strict_types=1);

namespace App\Domain\Finance\Resources;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin LedgerEntry */
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
            'owner_share_cents' => $this->owner_share_cents,
            'total_expenses_cents' => $this->totalExpensesCents(),
            'net_income_cents' => $this->netIncomeCents(),
            'currency' => 'PHP',
            'route' => $this->route,
            'remarks' => $this->remarks,

            'trip_id' => $this->trip_id,
            'trip_reference' => $this->trip?->reference,

            'customer_id' => $this->customer_id,

            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
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
