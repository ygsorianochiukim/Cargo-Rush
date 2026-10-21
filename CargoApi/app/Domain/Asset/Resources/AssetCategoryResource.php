<?php

declare(strict_types=1);

namespace App\Domain\Asset\Resources;

use App\Domain\Asset\Models\AssetCategory;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin AssetCategory */
class AssetCategoryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status?->value,
            'assets_count' => $this->whenCounted('assets'),
        ];
    }
}
