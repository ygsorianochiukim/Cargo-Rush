<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Supplier\Models\SupplierCategory;
use Illuminate\Http\Request;

/** @mixin SupplierCategory */
class SupplierCategoryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status?->value,
            'suppliers_count' => $this->whenCounted('suppliers'),
        ];
    }
}
