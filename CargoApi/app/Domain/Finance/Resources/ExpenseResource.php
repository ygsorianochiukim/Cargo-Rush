<?php

declare(strict_types=1);

namespace App\Domain\Finance\Resources;

use App\Domain\Finance\Models\Expense;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Expense
 */
class ExpenseResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'category_key' => $this->category?->key,
            'category_icon' => $this->category?->icon,
            'truck_id' => $this->truck_id,
            // Null reads as overhead in the clients, which is what it means.
            'truck_label' => $this->truck?->label,

            /**
             * Who it was bought from.
             *
             * The name rides along so a list can print it without a query per
             * row; it is null for the one-offs that only ever had a `payee`,
             * and the screen falls back to that.
             */
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'trip_id' => $this->trip_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'ledger_entry_id' => $this->ledger_entry_id,
            'date' => $this->date?->toDateString(),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'payee' => $this->payee,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
