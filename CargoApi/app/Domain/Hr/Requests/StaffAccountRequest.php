<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\Role as SystemRole;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Giving an employee a login, and saying what it can do.
 *
 * The role comes from the `roles` table, so anything the office has added is
 * offered here without a code change. `customer` is excluded: that account is
 * the firm's, tied to a `customers` row, and handing it to a member of staff
 * would produce a login whose portal endpoints have no customer to scope to.
 */
class StaffAccountRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'key')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
                Rule::notIn([SystemRole::Customer->value]),
            ],
            // Optional: falls back to the configured starting password, which
            // is what lets the desk hand over credentials the same afternoon.
            'password' => ['sometimes', 'string', 'min:8', 'max:72'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'That address already belongs to another account.',
            'role.exists' => 'That role does not exist, or has been switched off.',
            'role.not_in' => 'A customer account belongs to a firm and is created from Customer Management.',
        ];
    }

    public function role(): string
    {
        return (string) $this->input('role');
    }
}
