<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\Role as SystemRole;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class RoleAssignmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'key')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
                Rule::notIn([SystemRole::Customer->value]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role.exists' => 'That role does not exist, or has been switched off.',
            'role.not_in' => 'A customer account belongs to a firm and is created from Customer Management.',
        ];
    }

    public function role(): string
    {
        return (string) $this->input('role');
    }
}
