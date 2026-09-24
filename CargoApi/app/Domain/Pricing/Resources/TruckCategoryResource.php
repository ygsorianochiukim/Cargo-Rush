<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Resources;

use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One kind of unit the firm runs.
 *
 * @mixin TruckCategory
 */
class TruckCategoryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'status' => $this->status?->value,

            /**
             * What depends on it, where it has been counted.
             *
             * Both matter before removing one: a category priced on the rate
             * card is carrying money, and one assigned to units is what
             * dispatch matches a freezer job against.
             */
            'bracket_count' => $this->whenCounted('brackets'),
            'vehicle_count' => $this->whenCounted('vehicles'),

            ...$this->stamps(),
        ];
    }
}
