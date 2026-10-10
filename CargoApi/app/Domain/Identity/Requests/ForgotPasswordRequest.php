<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class ForgotPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:160'],
        ];
    }
}
