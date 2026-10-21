<?php

declare(strict_types=1);

namespace App\Domain\Asset\Resources;

use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Asset */
class AssetResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tag' => $this->tag,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'serial_no' => $this->serial_no,
            'purchased_on' => $this->purchased_on?->toDateString(),
            'cost_cents' => $this->cost_cents,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'custodian_id' => $this->custodian_id,
            'custodian_name' => $this->custodian?->fullName(),
            'assigned_on' => $this->assigned_on?->toDateString(),
            'location' => $this->location,
            'condition' => $this->condition,
            'status' => $this->status->value,
            'note' => $this->note,

            ...$this->stamps(),
        ];
    }
}
