<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Http\Request;

/** @mixin Supplier */
class SupplierResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $counted = $this->resource->getAttribute('expense_spend_cents') !== null
            || $this->resource->getAttribute('service_spend_cents') !== null
            || $this->resource->getAttribute('billed_cents') !== null;

        $expense = (int) ($this->expense_spend_cents ?? 0);
        $service = (int) ($this->service_spend_cents ?? 0);
        $billed = (int) ($this->billed_cents ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'contact' => $this->contact,
            'address' => $this->address,
            'supplies' => $this->supplies,
            'note' => $this->note,
            'status' => $this->status?->value,

            'expense_spend_cents' => $counted ? $expense : null,
            'service_spend_cents' => $counted ? $service : null,
            'billed_cents' => $counted ? $billed : null,
            'spend_cents' => $counted ? $expense + $service + $billed : null,

            'expenses_count' => $this->whenCounted('expenses'),
        ];
    }
}
