<?php

declare(strict_types=1);

namespace App\Domain\Finance\Resources;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin ExpenseCategory
 */
class ExpenseCategoryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'position' => $this->position,
            'status' => $this->status->value,
            // Present only where the caller asked for it, so the plain list
            // does not run a count per row.
            'expense_count' => $this->whenCounted('expenses'),

            ...$this->stamps(),
        ];
    }
}
