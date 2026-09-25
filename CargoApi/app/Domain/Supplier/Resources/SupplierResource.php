<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Http\Request;

/**
 * @mixin Supplier
 */
class SupplierResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        /**
         * The three kinds of spend, and their total.
         *
         * Present only on a list or a show that went through
         * `SupplierRepository::query()` — a supplier reached any other way has
         * no aggregates loaded, and `?? 0` would then report a real supplier as
         * having cost nothing. Null says "not counted here" instead, which is
         * the honest answer and the one a client can tell apart.
         */
        $counted = $this->resource->getAttribute('expense_spend_cents') !== null
            || $this->resource->getAttribute('service_spend_cents') !== null
            || $this->resource->getAttribute('billed_cents') !== null;

        $expense = (int) ($this->expense_spend_cents ?? 0);
        $service = (int) ($this->service_spend_cents ?? 0);
        $billed = (int) ($this->billed_cents ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact' => $this->contact,
            'address' => $this->address,
            'supplies' => $this->supplies,
            'note' => $this->note,
            'status' => $this->status?->value,

            // Kept apart rather than added, because what kind of spend it was
            // is the thing that distinguishes a garage from a chandler. The
            // total rides along so no client adds up three numbers its own way.
            'expense_spend_cents' => $counted ? $expense : null,
            'service_spend_cents' => $counted ? $service : null,
            'billed_cents' => $counted ? $billed : null,
            'spend_cents' => $counted ? $expense + $service + $billed : null,

            'expenses_count' => $this->whenCounted('expenses'),
        ];
    }
}
