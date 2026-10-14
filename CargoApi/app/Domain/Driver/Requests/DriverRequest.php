<?php

declare(strict_types=1);

namespace App\Domain\Driver\Requests;

use App\Domain\Driver\DTO\DriverData;
use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DriverRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $licence = $this->forHelper() ? 'nullable' : $required;

        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['sometimes', Rule::in(CrewRole::values())],
            'name' => [$required, 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:40'],
            'licence_no' => [
                $licence, 'string', 'max:40',
                Rule::unique('drivers', 'licence_no')->ignore($this->route('driver')),
            ],
            'licence_expiry' => [$licence, 'date'],
            'violations' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],
        ];
    }

    private function forHelper(): bool
    {
        $current = $this->route('driver');
        $role = $this->input('role') ?? ($current instanceof Driver ? $current->role?->value : null);

        return $role === CrewRole::Helper->value;
    }

    public function toData(): DriverData
    {
        return DriverData::fromArray($this->validated());
    }
}
