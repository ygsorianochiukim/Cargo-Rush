<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\Role as SystemRole;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing the role on an account that already exists.
 *
 * Its own class rather than reusing `StaffAccountRequest`: that one requires an
 * unused email address, which is the right rule when creating a login and an
 * impossible one when changing the role on a login that already has one.
 *
 * The role is checked against the `roles` table rather than a fixed list, so a
 * Treasury Officer added by the office is assignable the moment it exists. Two
 * roles are excluded whatever the table says: `customer`, because that account
 * belongs to a firm and is created from Customer Management — a member of staff
 * given one would have no customer to scope to, and every portal endpoint would
 * fail. And an inactive role, because switching somebody into one is a way to
 * strand them with no working menu.
 */
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
