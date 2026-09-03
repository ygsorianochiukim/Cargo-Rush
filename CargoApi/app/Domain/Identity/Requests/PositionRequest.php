<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PositionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:160'],
            // Null is a real answer: a mechanic normally has no login at all.
            'default_role_id' => ['nullable', 'string', 'exists:roles,id'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
        ];
    }
}
