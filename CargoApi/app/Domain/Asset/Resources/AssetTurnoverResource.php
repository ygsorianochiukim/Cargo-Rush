<?php

declare(strict_types=1);

namespace App\Domain\Asset\Resources;

use App\Domain\Asset\Models\AssetTurnover;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin AssetTurnover */
class AssetTurnoverResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset_name' => $this->whenLoaded('asset', fn () => $this->asset?->name),
            'asset_tag' => $this->whenLoaded('asset', fn () => $this->asset?->tag),
            'from_employee_id' => $this->from_employee_id,
            'from_name' => $this->from?->fullName(),
            'to_employee_id' => $this->to_employee_id,
            'to_name' => $this->to?->fullName(),
            'turned_over_on' => $this->turned_over_on?->toDateString(),
            'condition' => $this->condition,
            'status' => $this->status->value,
            'note' => $this->note,
            'recorded_by_name' => $this->recorder?->name,

            ...$this->stamps(),
        ];
    }
}
