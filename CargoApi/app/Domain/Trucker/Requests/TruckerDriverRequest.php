<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TruckerDriverRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'licence_no' => [
                $required, 'string', 'max:60',
                Rule::unique('trucker_drivers', 'licence_no')
                    ->where('trucker_id', $this->user()?->trucker?->getKey())
                    ->ignore($this->route('driverId')),
            ],
            'licence_expiry' => ['nullable', 'date', 'after:today'],

            'email' => $this->creating()
                ? ['required', 'email', 'max:160', 'unique:users,email']
                : ['prohibited'],
            'password' => $this->creating()
                ? ['required', Password::defaults()]
                : ['prohibited'],

            'status' => ['sometimes', 'string', 'in:'.implode(',', [
                StatusValue::Active->value,
                StatusValue::Inactive->value,
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_no.required' => 'Every driver needs a licence number on file.',
            'licence_no.unique' => 'You already have a driver with that licence number.',
            'email.unique' => 'That address already has an account.',
            'licence_expiry.after' => 'That licence has expired.',
        ];
    }
}
