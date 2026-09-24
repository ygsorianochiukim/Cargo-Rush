<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * A partner's truck, added or corrected.
 *
 * Used by the partner for their own record and by the desk for anybody's. The
 * fields are identical either way — a plate is a plate — and who may call it is
 * the route's business.
 */
class TruckerVehicleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'plate' => [$this->requiredOnCreate(), 'string', 'max:20'],
            'model' => [$this->requiredOnCreate(), 'string', 'max:120'],
            'capacity_kg' => [$this->requiredOnCreate(), 'integer', 'min:100', 'max:100000'],
            'truck_category_id' => ['nullable', 'string', 'max:26'],
            /**
             * Only the two idle states. A truck's `status` here says whether it
             * is running or in the shop, and there is no third thing for a
             * partner to set it to — a unit under a load is described by the
             * trip it is under, not by a column somebody can contradict.
             */
            'status' => ['sometimes', 'string', 'in:'.implode(',', [
                StatusValue::Available->value,
                StatusValue::Maintenance->value,
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'capacity_kg.required' => 'How much can it carry? It decides which jobs you are offered.',
        ];
    }
}
