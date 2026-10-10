<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\DTO\RegistrationData;
use Illuminate\Validation\Rules\Password;

class RegisterCompanyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:200'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'Your company needs a name.',
            'latitude.required_with' => 'A longitude needs its latitude.',
            'longitude.required_with' => 'A latitude needs its longitude.',
            'email.unique' => 'That address already has an account. Sign in instead.',
        ];
    }

    public function toData(): RegistrationData
    {
        return RegistrationData::fromArray($this->validated());
    }
}
