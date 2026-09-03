<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:160'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],

            // Permission *keys*, checked against the vocabulary. A key that is
            // not in the table gates nothing, so accepting it would produce a
            // role that looks like it grants something and does not.
            'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.exists' => 'That permission does not exist, so it would not grant anything.',
        ];
    }
}
