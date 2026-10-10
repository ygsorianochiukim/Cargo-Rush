<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Identity\DTO\CredentialsData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function toData(): CredentialsData
    {
        return CredentialsData::fromArray($this->validated());
    }
}
