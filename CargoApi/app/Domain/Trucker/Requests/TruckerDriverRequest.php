<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * One of a trucker's drivers, added or corrected by the trucker.
 *
 * The login is set once, on add. Changing a driver's email or password
 * afterwards is theirs to do from their own account, not the owner's.
 */
class TruckerDriverRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            // Once per trucker, as the index says — asked here so a clash is a
            // sentence and not a 500. Removed drivers count, because the index
            // counts them.
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

            // Only the owner's two: driving for them, or stood down.
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
