<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Customer\DTO\ShipperRegistrationData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterShipperRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],

            'contact_phone' => ['nullable', 'string', 'max:40'],

            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name — it is what a carrier books you under.',
            'email.unique' => 'That address already has an account. Sign in instead.',
        ];
    }

    public function toData(): ShipperRegistrationData
    {
        return ShipperRegistrationData::fromArray($this->validated());
    }
}
