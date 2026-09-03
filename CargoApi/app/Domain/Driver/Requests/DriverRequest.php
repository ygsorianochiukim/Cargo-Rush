<?php

declare(strict_types=1);

namespace App\Domain\Driver\Requests;

use App\Domain\Driver\DTO\DriverData;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DriverRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => [$required, 'string', 'max:120'],
            'licence_no' => [
                $required, 'string', 'max:40',
                // Unique across the roster, ignoring this driver on a PATCH.
                Rule::unique('drivers', 'licence_no')->ignore($this->route('driver')),
            ],
            'licence_expiry' => [$required, 'date'],
            'violations' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],
        ];
    }

    public function toData(): DriverData
    {
        return DriverData::fromArray($this->validated());
    }
}
