<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'That is the password you already have.',
        ];
    }
}
