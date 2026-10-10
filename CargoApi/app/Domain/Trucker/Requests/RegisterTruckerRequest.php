<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trucker\DTO\TruckerRegistrationData;
use Illuminate\Validation\Rules\Password;

class RegisterTruckerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'business_name' => ['required', 'string', 'max:160'],

            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'company_id' => ['nullable', 'string', 'max:26'],

            'licence_no' => ['nullable', 'string', 'max:60'],
            'licence_expiry' => ['nullable', 'date', 'after:today'],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name — it is what the fleet books you under.',
            'business_name.required' => 'Enter the name of your trucking service.',
            'email.unique' => 'That address already has an account. Sign in instead.',
            'licence_expiry.after' => 'That licence has expired. Renew it before registering.',
        ];
    }

    public function toData(): TruckerRegistrationData
    {
        return TruckerRegistrationData::fromArray($this->validated());
    }
}
